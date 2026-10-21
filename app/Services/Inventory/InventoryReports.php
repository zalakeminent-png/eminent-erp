<?php

namespace App\Services\Inventory;

use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\Balance;
use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\StockCountLine;
use App\Models\User;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use App\Support\Search;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The 18 inventory reports. Each is a live query over posted data
 * (balances, ledger, documents), never stored figures. Quantities are
 * reported per item in its base UOM; values only where real costs exist.
 */
class InventoryReports
{
    public const REPORTS = [
        'current-stock' => 'Current Stock Report',
        'warehouse-stock' => 'Warehouse-wise Stock',
        'location-stock' => 'Location-wise Stock',
        'raw-material-stock' => 'Raw Material Stock',
        'wip-stock' => 'WIP Stock',
        'finished-goods-stock' => 'Finished Goods Stock',
        'low-stock' => 'Low Stock Alert',
        'stock-movement' => 'Stock Movement Report',
        'material-inward' => 'Material Inward Report',
        'material-issue' => 'Material Issue Report',
        'material-return' => 'Material Return Report',
        'stock-transfer' => 'Stock Transfer Report',
        'scrap-rejection' => 'Scrap & Rejection Report',
        'stock-adjustment' => 'Stock Adjustment Report',
        'stock-reservation' => 'Stock Reservation Report',
        'physical-reconciliation' => 'Physical Stock Reconciliation',
        'stock-aging' => 'Stock Aging Report',
        'inventory-valuation' => 'Inventory Valuation',
    ];

    public function __construct(
        private readonly StockBrowser $browser,
        private readonly StructureScope $scope,
    ) {}

    /**
     * @return array{columns: list<array{key: string, label: string}>, query: Builder|QueryBuilder, map: Closure, summary?: array}
     */
    public function build(string $report, array $filters, User $user): array
    {
        return match ($report) {
            'current-stock' => $this->balanceReport($filters, $user, 'stock'),
            'location-stock' => $this->balanceReport([...$filters, 'sort' => 'location'], $user, 'stock'),
            'raw-material-stock' => $this->balanceReport($filters, $user, 'raw-material'),
            'wip-stock' => $this->balanceReport($filters, $user, 'wip'),
            'finished-goods-stock' => $this->balanceReport($filters, $user, 'finished-goods'),
            'warehouse-stock' => $this->warehouseStock($filters, $user),
            'low-stock' => $this->lowStock($filters, $user),
            'stock-movement' => $this->movements($filters, $user),
            'material-inward' => $this->documentLines(InventoryDocument::GRN, $filters, $user),
            'material-issue' => $this->documentLines(InventoryDocument::MATERIAL_ISSUE, $filters, $user),
            'material-return' => $this->documentLines(InventoryDocument::MATERIAL_RETURN, $filters, $user),
            'stock-transfer' => $this->documentLines(InventoryDocument::TRANSFER, $filters, $user),
            'scrap-rejection' => $this->documentLines(InventoryDocument::SCRAP, $filters, $user),
            'stock-adjustment' => $this->documentLines(InventoryDocument::ADJUSTMENT, $filters, $user),
            'stock-reservation' => $this->reservations($filters, $user),
            'physical-reconciliation' => $this->reconciliation($filters, $user),
            'stock-aging' => $this->aging($filters, $user),
            'inventory-valuation' => $this->valuation($filters, $user),
            default => throw ValidationException::withMessages(['report' => 'Unknown report.']),
        };
    }

    // ---- balance based -----------------------------------------------------

    private function balanceReport(array $filters, User $user, string $preset): array
    {
        return [
            'columns' => $this->cols([
                'item_code' => 'Item Code', 'item_name' => 'Item Name', 'category' => 'Category', 'warehouse' => 'Warehouse',
                'department' => 'Department', 'location' => 'Location', 'stock_status' => 'Status', 'quantity' => 'Quantity',
                'uom' => 'UOM', 'value' => 'Value', 'last_movement_at' => 'Last Movement',
            ]),
            'query' => $this->browser->balances($filters, $user, $preset),
            'map' => fn (Balance $b) => [
                'item_code' => $b->item?->code, 'item_name' => $b->item?->name, 'category' => $b->item?->category?->name,
                'warehouse' => $b->warehouse?->code, 'department' => $b->warehouse?->department?->name, 'location' => $b->location?->code,
                'stock_status' => $b->stock_status, 'quantity' => Refs::decimal($b->quantity), 'uom' => $b->baseUom?->code,
                'value' => $b->value === null ? null : Refs::decimal($b->value), 'last_movement_at' => $b->last_movement_at?->toDateTimeString(),
            ],
        ];
    }

    private function warehouseStock(array $filters, User $user): array
    {
        $base = $this->browser->balances($filters, $user)->getQuery();
        $base->orders = null;
        $base->columns = null;
        $query = $base->join('units_of_measure as inv_u', 'inv_u.id', '=', 'inventory_balances.base_uom_id')
            ->selectRaw('inv_w.code as warehouse_code, inv_w.name as warehouse_name, inv_i.code as item_code, inv_i.name as item_name,
                inventory_balances.stock_status, inv_u.code as uom_code, SUM(inventory_balances.quantity) as quantity,
                SUM(inventory_balances.value) as value, SUM(CASE WHEN inventory_balances.value IS NULL THEN 1 ELSE 0 END) as unknown_values')
            ->groupBy('inv_w.code', 'inv_w.name', 'inv_i.code', 'inv_i.name', 'inventory_balances.stock_status', 'inv_u.code')
            ->orderBy('inv_w.code')->orderBy('inv_i.code')->orderBy('inventory_balances.stock_status');

        return [
            'columns' => $this->cols(['warehouse' => 'Warehouse', 'item_code' => 'Item Code', 'item_name' => 'Item Name', 'stock_status' => 'Status', 'quantity' => 'Quantity', 'uom' => 'UOM', 'value' => 'Value']),
            'query' => $query,
            'map' => fn ($r) => [
                'warehouse' => $r->warehouse_code, 'item_code' => $r->item_code, 'item_name' => $r->item_name, 'stock_status' => $r->stock_status,
                'quantity' => Decimal::normalize((string) $r->quantity), 'uom' => $r->uom_code,
                'value' => (int) $r->unknown_values > 0 || $r->value === null ? null : Decimal::normalize((string) $r->value),
            ],
        ];
    }

    private function lowStock(array $filters, User $user): array
    {
        $factories = $this->scope->factoryIds($user);
        $available = DB::table('inventory_balances')->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('inventory_balances.item_id', 'items.id')->where('stock_status', 'available')
            ->when($factories !== null, fn ($q) => $q->whereIn('factory_id', $factories))
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(! empty($filters['factory_id']), fn ($q) => $q->where('factory_id', $filters['factory_id']));

        $items = DB::table('items')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.base_uom_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'items.category_id')
            ->where('items.is_active', true)->where('items.track_inventory', true)
            ->where(fn ($q) => $q->whereNotNull('items.reorder_level')->orWhereNotNull('items.min_stock_level'))
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('items.category_id', $filters['category_id']))
            ->when(! empty($filters['item_type']), fn ($q) => $q->where('items.item_type', $filters['item_type']))
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(items.code) LIKE ?', [Search::like($filters['search'])])->orWhereRaw('LOWER(items.name) LIKE ?', [Search::like($filters['search'])])))
            ->select(['items.code', 'items.name', 'items.reorder_level', 'items.min_stock_level', 'units_of_measure.code as uom_code', 'item_categories.name as category'])
            ->selectSub($available, 'available');

        $query = DB::query()->fromSub($items, 'low')
            ->whereRaw('low.available <= COALESCE(low.reorder_level, low.min_stock_level)')
            ->orderBy('low.code');

        return [
            'columns' => $this->cols(['item_code' => 'Item Code', 'item_name' => 'Item Name', 'category' => 'Category', 'available' => 'Available', 'reorder_level' => 'Reorder Level', 'min_stock_level' => 'Minimum Level', 'shortfall' => 'Shortfall to Reorder', 'uom' => 'UOM']),
            'query' => $query,
            'map' => fn ($r) => [
                'item_code' => $r->code, 'item_name' => $r->name, 'category' => $r->category,
                'available' => Decimal::normalize((string) $r->available), 'reorder_level' => Refs::decimal($r->reorder_level),
                'min_stock_level' => Refs::decimal($r->min_stock_level),
                'shortfall' => Decimal::normalize(bcsub((string) ($r->reorder_level ?? $r->min_stock_level), (string) $r->available, 6)),
                'uom' => $r->uom_code,
            ],
        ];
    }

    private function aging(array $filters, User $user): array
    {
        $query = $this->browser->balances($filters, $user)->where('inventory_balances.quantity', '>', 0);

        return [
            'columns' => $this->cols(['item_code' => 'Item Code', 'item_name' => 'Item Name', 'warehouse' => 'Warehouse', 'location' => 'Location', 'stock_status' => 'Status', 'quantity' => 'Quantity', 'uom' => 'UOM', 'last_receipt_at' => 'Last Receipt', 'age_days' => 'Days Since Last Receipt', 'bucket' => 'Age Bucket']),
            'query' => $query,
            'map' => function (Balance $b) {
                $days = $b->last_receipt_at ? (int) $b->last_receipt_at->startOfDay()->diffInDays(now()->startOfDay()) : null;

                return [
                    'item_code' => $b->item?->code, 'item_name' => $b->item?->name, 'warehouse' => $b->warehouse?->code, 'location' => $b->location?->code,
                    'stock_status' => $b->stock_status, 'quantity' => Refs::decimal($b->quantity), 'uom' => $b->baseUom?->code,
                    'last_receipt_at' => $b->last_receipt_at?->toDateString(), 'age_days' => $days,
                    'bucket' => match (true) {
                        $days === null => 'Unknown', $days <= 30 => '0-30', $days <= 60 => '31-60', $days <= 90 => '61-90', $days <= 180 => '91-180', default => '180+',
                    },
                ];
            },
        ];
    }

    private function valuation(array $filters, User $user): array
    {
        $known = $this->browser->balances($filters, $user)->whereNotNull('inventory_balances.value');
        $unknown = $this->browser->balances($filters, $user)->whereNull('inventory_balances.value');
        $total = (clone $known)->reorder()->sum('inventory_balances.value');

        return [
            'columns' => $this->cols(['item_code' => 'Item Code', 'item_name' => 'Item Name', 'warehouse' => 'Warehouse', 'location' => 'Location', 'stock_status' => 'Status', 'quantity' => 'Quantity', 'uom' => 'UOM', 'average_cost' => 'Average Unit Cost', 'value' => 'Value']),
            'query' => $known,
            'map' => fn (Balance $b) => [
                'item_code' => $b->item?->code, 'item_name' => $b->item?->name, 'warehouse' => $b->warehouse?->code, 'location' => $b->location?->code,
                'stock_status' => $b->stock_status, 'quantity' => Refs::decimal($b->quantity), 'uom' => $b->baseUom?->code,
                'average_cost' => bccomp((string) $b->quantity, '0', 6) !== 0 ? Decimal::round(bcdiv((string) $b->value, (string) $b->quantity, 10), 6) : null,
                'value' => Refs::decimal($b->value),
            ],
            'summary' => [
                'known_value_total' => Decimal::normalize((string) $total),
                'balances_without_cost' => (clone $unknown)->reorder()->count(),
                'note' => 'Totals include only stock with recorded costs. Stock received without a cost is listed as unvalued and is not estimated.',
            ],
        ];
    }

    // ---- ledger / documents ------------------------------------------------

    private function movements(array $filters, User $user): array
    {
        return [
            'columns' => $this->cols(['posted_at' => 'Posted At', 'transaction_number' => 'Transaction', 'transaction_type' => 'Type', 'item_code' => 'Item Code', 'warehouse' => 'Warehouse', 'location' => 'Location', 'stock_status' => 'Status', 'quantity_in' => 'Qty In', 'quantity_out' => 'Qty Out', 'uom' => 'UOM', 'running_balance' => 'Running Balance', 'reference' => 'Reference', 'created_by' => 'Created By']),
            'query' => $this->browser->ledger($filters, $user),
            'map' => function (LedgerEntry $e) {
                $row = $this->browser->ledgerRow($e);

                return [
                    'posted_at' => $e->posted_at?->toDateTimeString(), 'transaction_number' => $row['transaction_number'], 'transaction_type' => $row['transaction_type'],
                    'item_code' => $e->item?->code, 'warehouse' => $row['warehouse']['code'] ?? null, 'location' => $row['location']['code'] ?? null,
                    'stock_status' => $e->stock_status, 'quantity_in' => $row['quantity_in'], 'quantity_out' => $row['quantity_out'], 'uom' => $row['uom']['code'] ?? null,
                    'running_balance' => $row['running_balance'], 'reference' => trim(($e->source_document_type ?? '').' #'.($e->source_document_id ?? ''), ' #'),
                    'created_by' => $row['created_by']['full_name'] ?? null,
                ];
            },
        ];
    }

    private function documentLines(string $type, array $filters, User $user): array
    {
        $factories = $this->scope->factoryIds($user);
        $query = DocumentLine::query()
            ->select('inventory_document_lines.*')
            ->join('inventory_documents as d', 'd.id', '=', 'inventory_document_lines.document_id')
            ->join('items as inv_i', 'inv_i.id', '=', 'inventory_document_lines.item_id')
            ->where('d.document_type', $type)
            ->with(['document.supplier', 'document.reasonCode', 'document.requester', 'document.approver', 'document.department', 'item', 'uom', 'baseUom', 'sourceLocation', 'destinationLocation'])
            ->when($factories !== null, fn ($q) => $q->whereIn('d.factory_id', $factories))
            ->when(! empty($filters['status']), fn ($q) => $q->where('d.status', $filters['status']), fn ($q) => $q->where('d.status', '!=', 'cancelled'))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('d.document_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('d.document_date', '<=', $filters['date_to']))
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('d.warehouse_id', $filters['warehouse_id']))
            ->when(! empty($filters['factory_id']), fn ($q) => $q->where('d.factory_id', $filters['factory_id']))
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('inventory_document_lines.item_id', $filters['item_id']))
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('inv_i.category_id', $filters['category_id']))
            ->when(! empty($filters['supplier_id']), fn ($q) => $q->where('d.supplier_id', $filters['supplier_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(d.document_number) LIKE ?', [Search::like($filters['search'])])
                ->orWhereRaw('LOWER(inv_i.code) LIKE ?', [Search::like($filters['search'])])))
            ->orderByDesc('d.document_date')->orderByDesc('d.id')->orderBy('inventory_document_lines.line_no');

        $columns = [
            'document_number' => 'Document', 'document_date' => 'Date', 'status' => 'Status', 'item_code' => 'Item Code', 'item_name' => 'Item Name',
            'quantity' => 'Quantity', 'uom' => 'UOM', 'base_quantity' => 'Base Qty', 'base_uom' => 'Base UOM',
            'from' => 'From', 'to' => 'To',
        ];
        $columns += match ($type) {
            InventoryDocument::GRN => ['supplier' => 'Supplier', 'invoice' => 'Invoice / Challan', 'to_status' => 'Received As', 'unit_cost' => 'Unit Cost'],
            InventoryDocument::TRANSFER => ['received' => 'Received (Base)', 'outstanding' => 'Outstanding (Base)'],
            InventoryDocument::MATERIAL_ISSUE => ['department' => 'Department', 'reference' => 'Production Reference'],
            InventoryDocument::MATERIAL_RETURN => ['condition' => 'Condition', 'to_status' => 'Returned As', 'reason' => 'Reason'],
            InventoryDocument::SCRAP => ['from_status' => 'From Status', 'to_status' => 'To Status', 'reason' => 'Reason', 'approved_by' => 'Authorized By'],
            InventoryDocument::ADJUSTMENT => ['from_status' => 'Stock Status', 'system' => 'System Qty (Base)', 'new' => 'New Qty (Base)', 'reason' => 'Reason', 'requested_by' => 'Requested By', 'approved_by' => 'Approved By'],
            default => [],
        };

        return [
            'columns' => $this->cols($columns),
            'query' => $query,
            'map' => function (DocumentLine $l) {
                $d = $l->document;

                return [
                    'document_number' => $d->document_number, 'document_date' => $d->document_date?->toDateString(), 'status' => $d->status,
                    'item_code' => $l->item?->code, 'item_name' => $l->item?->name, 'quantity' => Refs::decimal($l->quantity), 'uom' => $l->uom?->code,
                    'base_quantity' => Refs::decimal($l->base_quantity), 'base_uom' => $l->baseUom?->code,
                    'from' => $l->sourceLocation?->code, 'to' => $l->destinationLocation?->code,
                    'supplier' => $d->supplier?->name, 'invoice' => $d->supplier_invoice_number, 'to_status' => $l->to_status, 'from_status' => $l->from_status,
                    'unit_cost' => Refs::decimal($l->unit_cost), 'received' => Refs::decimal($l->received_base_quantity),
                    'outstanding' => Decimal::normalize(bcsub((string) $l->base_quantity, (string) $l->received_base_quantity, 6)),
                    'department' => $d->department?->name, 'reference' => $d->reference_number, 'condition' => $l->condition,
                    'reason' => $d->reasonCode?->name, 'requested_by' => $d->requester?->full_name, 'approved_by' => $d->approver?->full_name,
                    'system' => Refs::decimal($l->system_base_quantity),
                    'new' => $l->system_base_quantity !== null ? Decimal::normalize(bcadd((string) $l->system_base_quantity, (string) $l->base_quantity, 6)) : null,
                ];
            },
        ];
    }

    private function reservations(array $filters, User $user): array
    {
        $query = Reservation::query()->with(['item', 'baseUom', 'warehouse', 'location', 'reservedBy']);
        $this->scope->applyByFactory($query, $user);
        $query->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(! empty($filters['factory_id']), fn ($q) => $q->where('factory_id', $filters['factory_id']))
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('item_id', $filters['item_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('id');

        return [
            'columns' => $this->cols(['reservation_number' => 'Reservation', 'item_code' => 'Item Code', 'warehouse' => 'Warehouse', 'location' => 'Location', 'reserved' => 'Reserved (Base)', 'released' => 'Released (Base)', 'open' => 'Open (Base)', 'uom' => 'UOM', 'reference' => 'Reference', 'status' => 'Status', 'expires_on' => 'Expires', 'reserved_by' => 'Reserved By']),
            'query' => $query,
            'map' => fn (Reservation $r) => [
                'reservation_number' => $r->reservation_number, 'item_code' => $r->item?->code, 'warehouse' => $r->warehouse?->code, 'location' => $r->location?->code,
                'reserved' => Refs::decimal($r->base_quantity), 'released' => Refs::decimal($r->released_base_quantity),
                'open' => in_array($r->status, Reservation::OPEN, true) ? $r->openBaseQuantity() : '0', 'uom' => $r->baseUom?->code,
                'reference' => trim($r->reference_type.' '.($r->reference_number ?? '')), 'status' => $r->status,
                'expires_on' => $r->expires_on?->toDateString(), 'reserved_by' => $r->reservedBy?->full_name,
            ],
        ];
    }

    private function reconciliation(array $filters, User $user): array
    {
        $factories = $this->scope->factoryIds($user);
        $query = StockCountLine::query()
            ->select('stock_count_lines.*')
            ->join('stock_count_sessions as s', 's.id', '=', 'stock_count_lines.session_id')
            ->with(['item', 'location', 'baseUom', 'counter'])
            ->addSelect(['s.count_number', 's.status as session_status', 's.cutoff_at as session_cutoff'])
            ->whereIn('s.status', ['submitted', 'approved', 'posted'])
            ->when($factories !== null, fn ($q) => $q->whereIn('s.factory_id', $factories))
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('s.warehouse_id', $filters['warehouse_id']))
            ->when(! empty($filters['factory_id']), fn ($q) => $q->where('s.factory_id', $filters['factory_id']))
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('stock_count_lines.item_id', $filters['item_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('s.cutoff_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('s.cutoff_at', '<=', $filters['date_to']))
            ->when(! empty($filters['variance_only']), fn ($q) => $q->whereColumn('stock_count_lines.counted_quantity', '!=', 'stock_count_lines.system_quantity'))
            ->orderByDesc('s.id')->orderBy('stock_count_lines.id');

        return [
            'columns' => $this->cols(['count_number' => 'Count', 'cutoff_at' => 'Cutoff', 'session_status' => 'Status', 'item_code' => 'Item Code', 'location' => 'Location', 'stock_status' => 'Stock Status', 'system' => 'System Qty', 'counted' => 'Counted Qty', 'variance' => 'Variance', 'uom' => 'UOM', 'counted_by' => 'Counted By']),
            'query' => $query,
            'map' => fn (StockCountLine $l) => [
                'count_number' => $l->count_number, 'cutoff_at' => $l->session_cutoff ? (string) $l->session_cutoff : null, 'session_status' => $l->session_status,
                'item_code' => $l->item?->code, 'location' => $l->location?->code, 'stock_status' => $l->stock_status,
                'system' => Refs::decimal($l->system_quantity), 'counted' => Refs::decimal($l->counted_quantity), 'variance' => $l->variance(),
                'uom' => $l->baseUom?->code, 'counted_by' => $l->counter?->full_name,
            ],
        ];
    }

    /** @return list<array{key: string, label: string}> */
    private function cols(array $columns): array
    {
        return collect($columns)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }
}
