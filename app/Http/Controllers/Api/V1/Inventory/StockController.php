<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\Balance;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\Reservation;
use App\Models\MasterData\Item;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\Inventory\StockBrowser;
use App\Services\Inventory\StockPostingService;
use App\Services\Inventory\StockQuery;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Stock visibility: overview, WIP, finished goods, QC and scrap/rejection
 * views (all read-only, derived from posted balances), item stock card,
 * and the stock purpose of warehouses/locations.
 */
class StockController extends Controller
{
    private const FILTERS = [
        'search', 'factory_id', 'warehouse_id', 'location_id', 'item_id', 'category_id', 'item_type', 'status', 'purpose',
        'department_id', 'sort', 'direction', 'include_zero',
    ];

    public function __construct(
        private readonly StockBrowser $browser,
        private readonly StockQuery $query,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request, string $preset = 'stock'): JsonResponse|BinaryFileResponse
    {
        $filters = $request->only(self::FILTERS);
        $query = $this->browser->balances($filters, $request->user(), $preset);

        if ($format = $request->query('export')) {
            abort_unless($request->user()->can('inventory.reports.export'), 403);

            return Spreadsheet::download("inventory-{$preset}", [
                'Item Code', 'Item Name', 'Category', 'Warehouse', 'Department', 'Location', 'Purpose', 'Status', 'Quantity', 'UOM', 'Value', 'Last Movement',
            ], $query->cursor()->map(fn (Balance $b) => [
                $b->item?->code, $b->item?->name, $b->item?->category?->name, $b->warehouse?->code, $b->warehouse?->department?->name,
                $b->location?->code, StockQuery::purpose($b->location), $b->stock_status, Refs::decimal($b->quantity), $b->baseUom?->code,
                $b->value === null ? 'Unknown' : Refs::decimal($b->value), $b->last_movement_at?->toDateTimeString(),
            ]), $format === 'csv' ? 'csv' : 'xlsx');
        }

        $page = $query->paginate(min(100, $request->integer('per_page', 25) ?: 25))->withQueryString();
        $reserved = $this->browser->reservedFor($page->items());

        return response()->json([
            'data' => collect($page->items())->map(fn (Balance $b) => $this->browser->balanceRow($b) + [
                'warehouse_reserved' => $reserved["{$b->item_id}|{$b->warehouse_id}"]['reserved'],
                'warehouse_available_to_promise' => $reserved["{$b->item_id}|{$b->warehouse_id}"]['available_to_promise'],
            ])->values(),
            'meta' => InventoryRefs::meta($page),
        ]);
    }

    /** Stock card for one item: totals, per-warehouse ATP, per-location balances, reservations, recent movements. */
    public function show(Request $request, int $itemId): JsonResponse
    {
        $item = Item::query()->with(['baseUom', 'category'])->findOrFail($itemId);
        $user = $request->user();
        $balances = $this->browser->balances(['item_id' => $item->id, 'include_zero' => $request->boolean('include_zero')], $user)->get();

        $warehouses = $balances->groupBy('warehouse_id')->map(function ($rows, $warehouseId) use ($item) {
            $summary = $this->query->summary($item->id, (int) $warehouseId);

            return ['warehouse' => Refs::coded($rows->first()->warehouse), 'factory' => Refs::coded($rows->first()->warehouse?->factory)] + $summary;
        })->values();

        $total = $balances->isEmpty() ? null : $this->query->summary($item->id);
        if ($total && $this->scope->factoryIds($user) !== null) {
            // A factory-scoped user only sees totals of the warehouses they can access.
            $total = null;
        }

        return response()->json([
            'data' => [
                'item' => Refs::coded($item) + ['item_type' => $item->item_type, 'category' => Refs::coded($item->category), 'requires_qc' => $item->requires_qc,
                    'reorder_level' => Refs::decimal($item->reorder_level), 'min_stock_level' => Refs::decimal($item->min_stock_level)],
                'uom' => Refs::uom($item->baseUom),
                'total' => $total,
                'warehouses' => $warehouses,
                'balances' => $balances->map(fn (Balance $b) => $this->browser->balanceRow($b))->values(),
                'reservations' => $this->browser->openReservations(['item_id' => $item->id], $user)->latest('id')->limit(50)->get()->map(fn (Reservation $r) => [
                    'id' => $r->id, 'reservation_number' => $r->reservation_number, 'warehouse' => Refs::coded($r->warehouse), 'factory' => Refs::coded($r->warehouse?->factory),
                    'location' => InventoryRefs::location($r->location), 'open_base_quantity' => $r->openBaseQuantity(),
                    'reference_type' => $r->reference_type, 'reference_number' => $r->reference_number, 'status' => $r->status,
                    'expires_on' => $r->expires_on?->toDateString(),
                ]),
                'recent_movements' => $this->browser->ledger(['item_id' => $item->id], $user)->limit(20)->get()->map(fn (LedgerEntry $e) => $this->browser->ledgerRow($e)),
            ],
        ]);
    }

    /** Available-to-promise check used by the editors' availability indicator. */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'location_id' => ['required', 'integer', 'exists:warehouse_locations,id'],
            'status' => ['nullable', Rule::in(array_keys(config('erp.inventory.stock_statuses')))],
        ]);
        $location = WarehouseLocation::query()->with('warehouse')->findOrFail($data['location_id']);
        $this->scope->ensureFactory($request->user(), $location->warehouse->factory_id);
        $status = $data['status'] ?? 'available';
        $onHand = $this->query->onHand($data['item_id'], $location->id, $status);

        return response()->json(['data' => [
            'on_hand' => $onHand,
            'reserved' => $status === 'available' ? $this->query->locationReserved($data['item_id'], $location->id) : '0',
            'available' => $status === 'available' ? Decimal::normalize($this->query->locationAtp($data['item_id'], $location->id)) : $onHand,
            'warehouse_available_to_promise' => Decimal::normalize($this->query->warehouseAtp($data['item_id'], $location->warehouse_id)),
            'locked_by_count' => app(StockPostingService::class)->lockedByCount($location)?->count_number,
        ]]);
    }

    /** Sets the stock purpose of a warehouse or a location (inventory settings). */
    public function updatePurpose(Request $request, string $kind, int $id, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate(['stock_purpose' => ['nullable', Rule::in(array_keys(config('erp.inventory.stock_purposes')))]]);
        $model = $kind === 'warehouses' ? Warehouse::query()->findOrFail($id) : WarehouseLocation::query()->with('warehouse')->findOrFail($id);
        $this->scope->ensureFactory($request->user(), $kind === 'warehouses' ? $model->factory_id : $model->warehouse->factory_id);

        $before = $model->stock_purpose;
        $model->forceFill(['stock_purpose' => $data['stock_purpose'] ?? null])->save();
        $logger->log('inventory.purpose.updated', $model, ['from' => $before, 'to' => $model->stock_purpose], "Stock purpose of {$model->code} set to ".($model->stock_purpose ?? 'inherited'));

        return response()->json(['data' => ['id' => $model->id, 'code' => $model->code, 'stock_purpose' => $model->stock_purpose], 'message' => 'Stock purpose saved.']);
    }
}
