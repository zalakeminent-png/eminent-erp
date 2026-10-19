<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\WarehouseRequest;
use App\Http\Resources\Structure\WarehouseResource;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    use ChangesStatus;

    private const SORTABLE = ['code', 'name', 'is_active', 'created_at'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'factory_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = $this->scope->applyByFactory(Warehouse::query(), $request->user())
            ->with(['factory', 'department', 'type', 'manager'])
            ->withCount('locations')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(code) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(name) LIKE ?', [Search::like($s)])))
            ->when($filters['factory_id'] ?? null, fn ($q, $id) => $q->where('factory_id', $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['type_id'] ?? null, fn ($q, $id) => $q->where('type_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id');

        if ($request->boolean('all')) {
            return WarehouseResource::collection($query->limit(1000)->get());
        }

        return WarehouseResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        $warehouse = DB::transaction(fn () => Warehouse::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]));

        $this->activity->log('warehouse.created', $warehouse, [
            'code' => $warehouse->code, 'name' => $warehouse->name, 'factory_id' => $warehouse->factory_id,
            'department_id' => $warehouse->department_id, 'manager_id' => $warehouse->manager_id,
        ], "Created warehouse {$warehouse->name}");

        return (new WarehouseResource($this->detail($warehouse)))
            ->additional(['message' => 'Warehouse created.'])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Warehouse $warehouse): WarehouseResource
    {
        $this->scope->ensureFactory($request->user(), $warehouse->factory_id);

        return new WarehouseResource($this->detail($warehouse));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $this->scope->ensureFactory($request->user(), $warehouse->factory_id);

        $data = collect($request->validated())->except('factory_id')->all();
        $before = $warehouse->only(array_keys($data));
        $warehouse->update($data);

        if ($changes = $this->activity->diff($before, $warehouse->only(array_keys($data)))) {
            $this->activity->log('warehouse.updated', $warehouse, ['changes' => $changes], "Updated warehouse {$warehouse->name}");
        }

        return new WarehouseResource($this->detail($warehouse));
    }

    public function updateStatus(Request $request, Warehouse $warehouse): WarehouseResource
    {
        $this->scope->ensureFactory($request->user(), $warehouse->factory_id);

        $this->changeStatus($request, $warehouse, 'warehouses', 'warehouse', fn (Warehouse $w, bool $activate) => $activate && ! $w->factory->is_active
            ? 'Activate the factory before activating this warehouse.'
            : null);

        return new WarehouseResource($this->detail($warehouse));
    }

    private function detail(Warehouse $warehouse): Warehouse
    {
        return $warehouse->load(['factory', 'department', 'type', 'manager', 'creator'])->loadCount('locations');
    }
}
