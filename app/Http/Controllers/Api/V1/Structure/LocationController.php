<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\LocationRequest;
use App\Http\Resources\Structure\LocationResource;
use App\Models\Factory;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\Structure\LocationHierarchy;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    use ChangesStatus;

    private const SORTABLE = ['code', 'name', 'type', 'is_active', 'created_at'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly StructureScope $scope,
        private readonly LocationHierarchy $hierarchy,
    ) {}

    /** Searchable table view. `selectable=1` returns only locations usable for new operations. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer'],
            'factory_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(array_keys(config('erp.structure.location_types')))],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_virtual' => ['nullable', 'boolean'],
            'selectable' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,200'],
        ]);

        $locations = $this->scope->applyToLocations(WarehouseLocation::query(), $request->user())
            ->with(['warehouse.factory', 'parent'])
            ->withCount('children')
            ->when($request->boolean('selectable'), fn ($q) => $q->selectable())
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(warehouse_locations.code) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(warehouse_locations.name) LIKE ?', [Search::like($s)])))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['factory_id'] ?? null, fn ($q, $id) => $q->whereHas('warehouse', fn ($w) => $w->where('factory_id', $id)))
            ->when($filters['parent_id'] ?? null, fn ($q, $id) => $q->where('parent_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('warehouse_locations.is_active', $s === 'active'))
            ->when(isset($filters['is_virtual']), fn ($q) => $q->where('is_virtual', $request->boolean('is_virtual')))
            ->orderBy('warehouse_locations.'.($filters['sort'] ?? 'code'), $filters['direction'] ?? 'asc')
            ->orderBy('warehouse_locations.id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return LocationResource::collection($locations);
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $location = DB::transaction(function () use ($data, $request) {
            // Serialize hierarchy changes per warehouse.
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($data['warehouse_id']);
            $this->hierarchy->validatePlacement($warehouse, $data['parent_id'] ?? null);

            return WarehouseLocation::create([
                ...$data,
                'is_virtual' => $request->boolean('is_virtual'),
                'is_pickable' => $request->boolean('is_pickable', true),
                'is_receivable' => $request->boolean('is_receivable', true),
                'is_active' => $request->boolean('is_active', true),
            ]);
        });

        $this->activity->log('location.created', $location, [
            'code' => $location->code, 'warehouse_id' => $location->warehouse_id, 'parent_id' => $location->parent_id,
            'type' => $location->type, 'is_virtual' => $location->is_virtual,
        ], "Created location {$location->code}");

        return (new LocationResource($this->detail($location)))
            ->additional(['message' => 'Location created.', 'path' => $this->hierarchy->path($location)])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, WarehouseLocation $location): JsonResponse
    {
        $this->ensureAccess($request, $location);

        return (new LocationResource($this->detail($location)))
            ->additional(['path' => $this->hierarchy->path($location)])
            ->response();
    }

    public function update(LocationRequest $request, WarehouseLocation $location): JsonResponse
    {
        $this->ensureAccess($request, $location);
        $data = collect($request->validated())->except('warehouse_id')->all();
        $data['parent_id'] = $data['parent_id'] ?? null;

        $before = $location->only(array_keys($data));

        DB::transaction(function () use ($location, $data) {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($location->warehouse_id);
            if ($data['parent_id'] !== $location->parent_id) {
                $this->hierarchy->validatePlacement($warehouse, $data['parent_id'], $location);
            }
            $location->update($data);
        });

        $changes = $this->activity->diff($before, $location->only(array_keys($data)));
        if (isset($changes['parent_id'])) {
            $this->activity->log('location.moved', $location, ['parent_id' => $changes['parent_id']], "Moved location {$location->code} in the hierarchy");
            unset($changes['parent_id']);
        }
        if ($changes) {
            $this->activity->log('location.updated', $location, ['changes' => $changes], "Updated location {$location->code}");
        }

        return (new LocationResource($this->detail($location)))
            ->additional(['path' => $this->hierarchy->path($location)])
            ->response();
    }

    public function updateStatus(Request $request, WarehouseLocation $location): LocationResource
    {
        $this->ensureAccess($request, $location);

        $this->changeStatus($request, $location, 'locations', 'location', function (WarehouseLocation $l, bool $activate) {
            if (! $activate && $l->children()->where('is_active', true)->exists()) {
                return 'Deactivate the child locations first.';
            }
            if ($activate && $l->parent && ! $l->parent->is_active) {
                return 'Activate the parent location first.';
            }
            if ($activate && ! $l->warehouse->is_active) {
                return 'Activate the warehouse before activating this location.';
            }

            return null;
        });

        return new LocationResource($this->detail($location));
    }

    /**
     * Permanent deletion is only possible for an unreferenced leaf location
     * (e.g. one created by mistake). Otherwise deactivate it.
     */
    public function destroy(Request $request, WarehouseLocation $location): JsonResponse
    {
        $this->ensureAccess($request, $location);

        if ($blockers = $this->hierarchy->deletionBlockers($location)) {
            return response()->json([
                'message' => 'This location cannot be deleted because '.implode(' and ', $blockers).'. Deactivate it instead.',
            ], 409);
        }

        $snapshot = $location->only(['id', 'code', 'name', 'warehouse_id', 'parent_id']);
        DB::transaction(fn () => $location->delete());
        $this->activity->log('location.deleted', null, $snapshot, "Deleted location {$snapshot['code']}");

        return response()->json(['message' => 'Location deleted.']);
    }

    /** GET /warehouses/{warehouse}/locations/tree */
    public function tree(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->scope->ensureFactory($request->user(), $warehouse->factory_id);
        $status = $request->validate(['status' => ['nullable', Rule::in(['active', 'inactive'])]])['status'] ?? null;

        $locations = $warehouse->locations()
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->get();

        return response()->json([
            'data' => [
                'warehouse' => ['id' => $warehouse->id, 'code' => $warehouse->code, 'name' => $warehouse->name, 'is_active' => $warehouse->is_active],
                'max_depth' => $this->hierarchy->maxDepth(),
                'total' => $locations->count(),
                'tree' => $this->hierarchy->tree($locations),
            ],
        ]);
    }

    /** GET /factories/{factory}/locations/tree — all storage locations of a factory. */
    public function factoryTree(Request $request, Factory $factory): JsonResponse
    {
        $this->scope->ensureFactory($request->user(), $factory->id);
        $status = $request->validate(['status' => ['nullable', Rule::in(['active', 'inactive'])]])['status'] ?? null;

        $locations = WarehouseLocation::query()
            ->whereIn('warehouse_id', $factory->warehouses()->select('id'))
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->get();

        return response()->json([
            'data' => [
                'factory' => ['id' => $factory->id, 'code' => $factory->code, 'name' => $factory->name, 'is_active' => $factory->is_active],
                'max_depth' => $this->hierarchy->maxDepth(),
                'total' => $locations->count(),
                'tree' => $this->hierarchy->tree($locations),
            ],
        ]);
    }

    private function ensureAccess(Request $request, WarehouseLocation $location): void
    {
        $this->scope->ensureFactory($request->user(), $location->warehouse->factory_id);
    }

    private function detail(WarehouseLocation $location): WarehouseLocation
    {
        return $location->load(['warehouse.factory', 'parent', 'creator'])->loadCount('children');
    }
}
