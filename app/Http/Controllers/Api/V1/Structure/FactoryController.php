<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\FactoryRequest;
use App\Http\Resources\Structure\FactoryResource;
use App\Models\Factory;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FactoryController extends Controller
{
    use ChangesStatus;

    private const SORTABLE = ['code', 'name', 'city', 'type', 'is_active', 'created_at'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'type' => ['nullable', Rule::in(array_keys(config('erp.structure.factory_types')))],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = $this->scope->applyToFactories(Factory::query(), $request->user())
            ->withCount(['departments', 'warehouses', 'users'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(code) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(name) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(city) LIKE ?', [Search::like($s)])))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id');

        if ($request->boolean('all')) {
            return FactoryResource::collection($query->limit(500)->get());
        }

        return FactoryResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(FactoryRequest $request): JsonResponse
    {
        $factory = DB::transaction(fn () => Factory::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]));

        $this->activity->log('factory.created', $factory, [
            'code' => $factory->code, 'name' => $factory->name, 'type' => $factory->type, 'is_active' => $factory->is_active,
        ], "Created factory {$factory->name}");

        return (new FactoryResource($this->detail($factory)))
            ->additional(['message' => 'Factory created.'])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Factory $factory): FactoryResource
    {
        $this->scope->ensureFactory($request->user(), $factory->id);

        return new FactoryResource($this->detail($factory));
    }

    public function update(FactoryRequest $request, Factory $factory): FactoryResource
    {
        $this->scope->ensureFactory($request->user(), $factory->id);

        $data = $request->validated();
        $before = $factory->only(array_keys($data));
        $factory->update($data);

        if ($changes = $this->activity->diff($before, $factory->only(array_keys($data)))) {
            $this->activity->log('factory.updated', $factory, ['changes' => $changes], "Updated factory {$factory->name}");
        }

        return new FactoryResource($this->detail($factory));
    }

    public function updateStatus(Request $request, Factory $factory): FactoryResource
    {
        $this->scope->ensureFactory($request->user(), $factory->id);
        // Deactivation does not cascade; it blocks new assignments and selections.
        $this->changeStatus($request, $factory, 'factories', 'factory');

        return new FactoryResource($this->detail($factory));
    }

    private function detail(Factory $factory): Factory
    {
        return $factory->load(['company', 'creator'])->loadCount(['departments', 'warehouses', 'users']);
    }
}
