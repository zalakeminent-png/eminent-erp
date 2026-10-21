<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Models\Department;
use App\Models\Factory;
use App\Models\MasterData\BusinessPartner;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dropdown sources for inventory screens, limited to the user's factory
 * scope. Lets warehouse staff pick warehouses/locations/suppliers without
 * needing the Module 2/3 administration permissions.
 */
class LookupController extends Controller
{
    public function __construct(private readonly StructureScope $scope) {}

    public function structure(Request $request): JsonResponse
    {
        $user = $request->user();
        $factories = $this->scope->applyToFactories(Factory::query(), $user)->orderBy('code')->get(['id', 'code', 'name', 'is_active']);
        $departments = $this->scope->applyByFactory(Department::query(), $user)->orderBy('code')->get(['id', 'factory_id', 'code', 'name', 'is_active']);
        $warehouses = $this->scope->applyByFactory(Warehouse::query(), $user)->orderBy('code')
            ->get(['id', 'factory_id', 'department_id', 'code', 'name', 'stock_purpose', 'is_active']);

        return response()->json(['data' => [
            'factories' => $factories,
            'departments' => $departments,
            'warehouses' => $warehouses,
            'stock_statuses' => collect(config('erp.inventory.stock_statuses'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'stock_purposes' => collect(config('erp.inventory.stock_purposes'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
        ]]);
    }

    public function locations(Request $request): JsonResponse
    {
        $query = WarehouseLocation::query()->with('warehouse')->orderBy('code');
        $this->scope->applyToLocations($query, $request->user());
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('factory_id')) {
            $query->whereHas('warehouse', fn ($w) => $w->where('factory_id', $request->integer('factory_id')));
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true)->whereHas('warehouse', fn ($w) => $w->where('is_active', true));
        }
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
        }
        $locations = $query->limit(500)->get();
        if ($request->filled('purpose')) {
            $purposes = explode(',', (string) $request->query('purpose'));
            $locations = $locations->filter(fn ($l) => in_array(InventoryRefs::location($l)['purpose'], $purposes, true));
        }

        return response()->json(['data' => $locations->map(fn ($l) => InventoryRefs::location($l) + ['stock_purpose' => $l->stock_purpose, 'is_active' => $l->is_active, 'factory_id' => $l->warehouse->factory_id])->values()]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $query = BusinessPartner::query()->where('is_supplier', true)->where('is_active', true)->orderBy('name');
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like]));
        }

        return response()->json(['data' => $query->limit(50)->get(['id', 'code', 'name'])]);
    }
}
