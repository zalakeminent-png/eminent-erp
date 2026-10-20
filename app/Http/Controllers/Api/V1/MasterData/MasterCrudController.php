<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\ActivityLogger;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared list / show / create / update / status behaviour for master data.
 * Route permissions are applied in routes/api.php; subclasses add
 * entity-specific filters and business rules through the hooks below.
 */
abstract class MasterCrudController extends Controller
{
    /** @var list<string> columns searched case-insensitively */
    protected array $searchable = ['code', 'name'];

    /** @var list<string> */
    protected array $sortable = ['code', 'name', 'is_active', 'created_at'];

    protected string $defaultSort = 'code';

    /** @var list<string> */
    protected array $with = [];

    /** @var list<string> */
    protected array $withCount = [];

    protected bool $companyScoped = true;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    /** @return class-string<FormRequest> */
    abstract protected function requestClass(): string;

    /** Audit action prefix, e.g. "uom". */
    abstract protected function entity(): string;

    /** Human label for messages, e.g. "unit". */
    abstract protected function label(): string;

    /** Permission required to deactivate (reactivation needs update). */
    abstract protected function deactivatePermission(): string;

    abstract protected function updatePermission(): string;

    protected function activity(): ActivityLogger
    {
        return app(ActivityLogger::class);
    }

    protected function query(Request $request): Builder
    {
        $query = ($this->modelClass())::query();

        return $this->companyScoped ? $query->where($query->qualifyColumn('company_id'), Company::current()?->id) : $query;
    }

    /** @return array<string, mixed> extra filter validation rules */
    protected function filterRules(): array
    {
        return [];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void {}

    /** Adjust validated data before saving. */
    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        return $data;
    }

    /** Extra writes in the same transaction (pivots, child rows). */
    protected function afterSave(Model $model, array $data, Request $request, bool $created): void {}

    /** Return an error message to block a status change. */
    protected function statusGuard(Model $model, bool $activate): ?string
    {
        return null;
    }

    protected function detail(Model $model): Model
    {
        return $model->load($this->with)->loadCount($this->withCount);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in($this->sortable)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
            'all' => ['nullable', 'boolean'],
            ...$this->filterRules(),
        ]);

        $query = $this->query($request)->with($this->with)->withCount($this->withCount);
        $table = $query->getModel()->getTable();

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($q) use ($search, $table) {
                foreach ($this->searchable as $column) {
                    $q->orWhereRaw('LOWER('.(str_contains($column, '.') ? $column : "{$table}.{$column}").') LIKE ?', [Search::like($search)]);
                }
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where("{$table}.is_active", $status === 'active');
        }
        $this->applyFilters($query, $filters, $request);

        $query->orderBy("{$table}.".($filters['sort'] ?? $this->defaultSort), $filters['direction'] ?? 'asc')->orderBy("{$table}.id");

        $resource = $this->resourceClass();
        if ($request->boolean('all')) {
            return $resource::collection($query->limit(2000)->get());
        }

        return $resource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function show(Request $request, int $id): JsonResource
    {
        $resource = $this->resourceClass();

        return new $resource($this->detail($this->find($request, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = app($this->requestClass())->validated();
        $data = $this->prepare($data, null, $request);

        $model = DB::transaction(function () use ($data, $request) {
            $class = $this->modelClass();
            $model = new $class;
            $model->fill($data);
            if (array_key_exists('is_active', $data)) {
                $model->is_active = (bool) $data['is_active'];
            }
            $model->save();
            $this->afterSave($model, $data, $request, true);

            return $model;
        });

        $model->refresh();
        $this->activity()->log("{$this->entity()}.created", $model, $this->auditSnapshot($model), 'Created '.$this->label().' '.$this->displayName($model));

        $resource = $this->resourceClass();

        return (new $resource($this->detail($model)))
            ->additional(['message' => ucfirst($this->label()).' created.'])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResource
    {
        $model = $this->find($request, $id);
        $data = app($this->requestClass())->validated();
        $data = $this->prepare($data, $model, $request);

        $fields = array_keys(array_intersect_key($data, array_flip($model->getFillable())));
        $before = $model->only($fields);

        DB::transaction(function () use ($model, $data, $request) {
            $model->fill(collect($data)->except('is_active')->all());
            $model->save();
            $this->afterSave($model, $data, $request, false);
        });

        if ($changes = $this->activity()->diff($before, $model->only($fields))) {
            $this->activity()->log("{$this->entity()}.updated", $model, ['changes' => $changes], 'Updated '.$this->label().' '.$this->displayName($model));
        }

        $resource = $this->resourceClass();

        return new $resource($this->detail($model->refresh()));
    }

    public function updateStatus(Request $request, int $id): JsonResource
    {
        $model = $this->find($request, $id);
        $activate = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        abort_unless($request->user()->can($activate ? $this->updatePermission() : $this->deactivatePermission()), 403);

        if ($model->is_active !== $activate) {
            if ($message = $this->statusGuard($model, $activate)) {
                throw ValidationException::withMessages(['is_active' => $message]);
            }
            $model->forceFill(['is_active' => $activate])->save();
            $this->activity()->log(
                "{$this->entity()}.".($activate ? 'activated' : 'deactivated'),
                $model,
                ['code' => $model->getAttribute('code')],
                ($activate ? 'Activated ' : 'Deactivated ').$this->label().' '.$this->displayName($model),
            );
        }

        $resource = $this->resourceClass();

        return new $resource($this->detail($model));
    }

    protected function find(Request $request, int $id): Model
    {
        return $this->query($request)->findOrFail($id);
    }

    protected function displayName(Model $model): string
    {
        return (string) ($model->getAttribute('code') ?? $model->getAttribute('name') ?? $model->getKey());
    }

    /** @return array<string, mixed> */
    protected function auditSnapshot(Model $model): array
    {
        return $this->activity()->sanitize(collect($model->only($model->getFillable()))->filter(fn ($v) => $v !== null && ! is_array($v))->all());
    }
}
