<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\MasterData\DocumentLifecycle;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * Shared endpoints for BOMs, routings and QC specifications:
 * list / show / create / update (drafts) / delete draft / approve / revise /
 * activate-deactivate approved versions.
 */
abstract class VersionedDocumentController extends Controller
{
    abstract protected function lifecycle(): DocumentLifecycle;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    /** @return class-string<FormRequest> */
    abstract protected function requestClass(): string;

    abstract protected function numberColumn(): string;

    abstract protected function versionColumn(): string;

    /** Name of the line array in requests ("lines" or "operations"). */
    abstract protected function linesKey(): string;

    /** @return list<string|\Closure> relations for the detail view */
    abstract protected function detailRelations(): array;

    abstract protected function linesRelation(): string;

    /** Persist a draft through the lifecycle service. */
    abstract protected function saveDraft(?Model $document, array $header, array $lines): Model;

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'item_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'inactive'])],
            'effective_on' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in([$this->numberColumn(), $this->versionColumn(), 'effective_from', 'status', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $query = $this->baseQuery()
            ->with(['item.baseUom', 'approver'])
            ->withCount($this->linesRelation())
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER('.$this->numberColumn().') LIKE ?', [Search::like($s)])
                ->orWhereHas('item', fn ($i) => $i->whereRaw('LOWER(code) LIKE ?', [Search::like($s)])->orWhereRaw('LOWER(name) LIKE ?', [Search::like($s)]))))
            ->when($filters['item_id'] ?? null, fn ($q, $id) => $q->where('item_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['effective_on'] ?? null, fn ($q, $d) => $q->effectiveOn($d))
            ->orderBy($filters['sort'] ?? 'updated_at', $filters['direction'] ?? (isset($filters['sort']) ? 'asc' : 'desc'))
            ->orderByDesc('id');

        $resource = $this->resourceClass();

        return $resource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function show(int $id): JsonResource
    {
        $resource = $this->resourceClass();

        return new $resource($this->find($id)->load($this->detailRelations()));
    }

    public function store(): JsonResponse
    {
        $data = app($this->requestClass())->validated();
        $document = $this->saveDraft(null, collect($data)->except($this->linesKey())->all(), $data[$this->linesKey()] ?? []);
        $resource = $this->resourceClass();

        return (new $resource($document->refresh()->load($this->detailRelations())))
            ->additional(['message' => 'Draft created.'])
            ->response()->setStatusCode(201);
    }

    public function update(int $id): JsonResource
    {
        $document = $this->find($id);
        $this->lifecycle()->ensureEditable($document);
        $data = app($this->requestClass())->validated();
        $document = $this->saveDraft($document, collect($data)->except($this->linesKey())->all(), $data[$this->linesKey()] ?? []);
        $resource = $this->resourceClass();

        return new $resource($document->refresh()->load($this->detailRelations()));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->lifecycle()->deleteDraft($this->find($id));

        return response()->json(['message' => 'Draft deleted.']);
    }

    public function approve(Request $request, int $id): JsonResource
    {
        $document = $this->lifecycle()->approve($this->find($id), $request->user());
        $resource = $this->resourceClass();

        return (new $resource($document->load($this->detailRelations())))->additional(['message' => 'Approved.']);
    }

    public function revise(int $id): JsonResponse
    {
        $copy = $this->lifecycle()->revise($this->find($id)->load($this->linesRelation()))->refresh();
        $resource = $this->resourceClass();

        return (new $resource($copy->load($this->detailRelations())))
            ->additional(['message' => 'New draft revision created.'])
            ->response()->setStatusCode(201);
    }

    public function updateStatus(Request $request, int $id): JsonResource
    {
        $active = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $document = $this->lifecycle()->setActive($this->find($id), $active);
        $resource = $this->resourceClass();

        return new $resource($document->load($this->detailRelations()));
    }

    protected function baseQuery(): Builder
    {
        return ($this->modelClass())::query()->whereHas('item', fn ($q) => $q->where('company_id', Company::current()?->id));
    }

    protected function find(int $id): Model
    {
        return $this->baseQuery()->findOrFail($id);
    }
}
