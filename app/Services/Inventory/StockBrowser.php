<?php

namespace App\Services\Inventory;

use App\Http\Resources\Inventory\InventoryRefs;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\Balance;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\Reservation;
use App\Models\User;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Filtered read queries over balances and the ledger, shared by the stock
 * screens, the ledger and the reports. All figures come from posted data;
 * quantities are always reported per item in its own base UOM.
 */
class StockBrowser
{
    /** Named views of the balance table. */
    public const PRESETS = [
        'stock' => [],
        'raw-material' => ['item_types' => ['raw_material']],
        'wip' => ['purposes' => ['production']],
        'finished-goods' => ['item_types' => ['finished_good']],
        'qc' => ['statuses' => ['qc_pending', 'qc_hold']],
        'scrap' => ['statuses' => ['rework', 'rejected', 'scrap']],
    ];

    public const PURPOSE_SQL = "COALESCE(inv_l.stock_purpose, inv_w.stock_purpose, 'general')";

    public function __construct(private readonly StructureScope $scope) {}

    /**
     * Non-zero balances matching the filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function balances(array $filters, User $user, string $preset = 'stock'): Builder
    {
        $preset = self::PRESETS[$preset] ?? [];
        $query = Balance::query()
            ->select('inventory_balances.*')
            ->join('warehouse_locations as inv_l', 'inv_l.id', '=', 'inventory_balances.location_id')
            ->join('warehouses as inv_w', 'inv_w.id', '=', 'inventory_balances.warehouse_id')
            ->join('items as inv_i', 'inv_i.id', '=', 'inventory_balances.item_id')
            ->with(['item.category', 'baseUom', 'location.warehouse', 'warehouse.department', 'warehouse.factory']);
        $this->scope->applyByFactory($query, $user, 'inventory_balances.factory_id');

        if (empty($filters['include_zero'])) {
            $query->where('inventory_balances.quantity', '!=', 0);
        }
        if ($types = $preset['item_types'] ?? null) {
            $query->whereIn('inv_i.item_type', $types);
        }
        if ($purposes = $preset['purposes'] ?? null) {
            $query->whereIn(DB::raw(self::PURPOSE_SQL), $purposes);
        }
        if ($statuses = $preset['statuses'] ?? null) {
            $query->whereIn('inventory_balances.stock_status', $statuses);
        }
        $this->commonFilters($query, $filters, 'inventory_balances');
        if (! empty($filters['status'])) {
            $query->where('inventory_balances.stock_status', $filters['status']);
        }
        if (! empty($filters['purpose'])) {
            $query->where(DB::raw(self::PURPOSE_SQL), $filters['purpose']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('inv_w.department_id', $filters['department_id']);
        }

        $sort = match ($filters['sort'] ?? null) {
            'quantity' => 'inventory_balances.quantity',
            'last_movement_at' => 'inventory_balances.last_movement_at',
            'location' => 'inv_l.code',
            'warehouse' => 'inv_w.code',
            default => 'inv_i.code',
        };

        return $query->orderBy($sort, ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('inv_l.code')->orderBy('inventory_balances.stock_status');
    }

    /** @param array<string, mixed> $filters */
    public function ledger(array $filters, User $user): Builder
    {
        $query = LedgerEntry::query()
            ->select('inventory_ledger_entries.*')
            ->join('items as inv_i', 'inv_i.id', '=', 'inventory_ledger_entries.item_id')
            ->join('warehouse_locations as inv_l', 'inv_l.id', '=', 'inventory_ledger_entries.location_id')
            ->join('warehouses as inv_w', 'inv_w.id', '=', 'inventory_ledger_entries.warehouse_id')
            ->join('inventory_transactions as inv_t', 'inv_t.id', '=', 'inventory_ledger_entries.transaction_id')
            ->with(['item', 'baseUom', 'location.warehouse', 'transaction', 'creator']);
        $this->scope->applyByFactory($query, $user, 'inventory_ledger_entries.factory_id');
        $this->commonFilters($query, $filters, 'inventory_ledger_entries');

        if (! empty($filters['status'])) {
            $query->where('inventory_ledger_entries.stock_status', $filters['status']);
        }
        if (! empty($filters['transaction_type'])) {
            $query->where('inv_t.transaction_type', $filters['transaction_type']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('inventory_ledger_entries.created_by', $filters['user_id']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('inv_w.department_id', $filters['department_id']);
        }
        if (! empty($filters['direction_filter'])) {
            $query->where('inventory_ledger_entries.quantity', $filters['direction_filter'] === 'in' ? '>' : '<', 0);
        }
        if (! empty($filters['date_from'])) {
            $query->where('inventory_ledger_entries.posted_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $query->where('inventory_ledger_entries.posted_at', '<=', $filters['date_to'].' 23:59:59');
        }
        if (! empty($filters['source_document_type'])) {
            $query->where('inventory_ledger_entries.source_document_type', $filters['source_document_type']);
        }
        if (! empty($filters['source_document_id'])) {
            $query->where('inventory_ledger_entries.source_document_id', $filters['source_document_id']);
        }

        // Chronological posting order (id breaks ties within a transaction).
        $direction = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('inventory_ledger_entries.posted_at', $direction)->orderBy('inventory_ledger_entries.id', $direction);
    }

    public function balanceRow(Balance $b): array
    {
        return [
            'id' => $b->id,
            'item' => Refs::coded($b->item) + ['item_type' => $b->item?->item_type, 'category' => Refs::coded($b->item?->category)],
            'warehouse' => Refs::coded($b->warehouse),
            'factory' => Refs::coded($b->warehouse?->factory),
            'department' => Refs::coded($b->warehouse?->department),
            'location' => InventoryRefs::location($b->location),
            'stock_status' => $b->stock_status,
            'quantity' => Refs::decimal($b->quantity),
            'uom' => Refs::uom($b->baseUom),
            'value' => $b->value === null ? null : Refs::decimal($b->value),
            'last_movement_at' => $b->last_movement_at?->toIso8601String(),
            'last_receipt_at' => $b->last_receipt_at?->toIso8601String(),
        ];
    }

    public function ledgerRow(LedgerEntry $e): array
    {
        $qty = Decimal::normalize((string) $e->quantity);
        $in = bccomp($qty, '0', 6) > 0;

        return [
            'id' => $e->id,
            'posted_at' => $e->posted_at?->toIso8601String(),
            'transaction_id' => $e->transaction_id,
            'transaction_number' => $e->transaction?->transaction_number,
            'transaction_type' => $e->transaction?->transaction_type,
            'reversal_of_id' => $e->transaction?->reversal_of_id,
            'item' => Refs::coded($e->item),
            'warehouse' => $e->location?->warehouse ? Refs::coded($e->location->warehouse) : null,
            'location' => InventoryRefs::location($e->location),
            'stock_status' => $e->stock_status,
            'quantity' => $qty,
            'quantity_in' => $in ? $qty : null,
            'quantity_out' => $in ? null : ltrim($qty, '-'),
            'uom' => Refs::uom($e->baseUom),
            'running_balance' => Refs::decimal($e->balance_after),
            'unit_cost' => Refs::decimal($e->unit_cost),
            'value_delta' => Refs::decimal($e->value_delta),
            'counterparty' => $e->counterparty,
            'source_document_type' => $e->source_document_type,
            'source_document_id' => $e->source_document_id,
            'source_document_line_id' => $e->source_document_line_id,
            'remarks' => $e->transaction?->remarks,
            'created_by' => Refs::user($e->creator),
        ];
    }

    /**
     * Item-level figures for a set of balances (physical / reserved / ATP per
     * item + warehouse), never mixing items or units.
     *
     * @param  iterable<Balance>  $balances
     * @return array<string, array{reserved: string, available_to_promise: string}>
     */
    public function reservedFor(iterable $balances): array
    {
        $out = [];
        $query = app(StockQuery::class);
        foreach ($balances as $b) {
            $key = "{$b->item_id}|{$b->warehouse_id}";
            if (! isset($out[$key])) {
                $out[$key] = [
                    'reserved' => $query->warehouseReserved($b->item_id, $b->warehouse_id),
                    'available_to_promise' => Decimal::normalize($query->warehouseAtp($b->item_id, $b->warehouse_id)),
                ];
            }
        }

        return $out;
    }

    /** Open reservation quantity per item (base UOM) for the given warehouse filter. */
    public function openReservations(array $filters, User $user): Builder
    {
        $query = Reservation::query()->holding()->with(['item', 'baseUom', 'warehouse.factory', 'location']);
        $this->scope->applyByFactory($query, $user);
        foreach (['item_id', 'warehouse_id', 'location_id', 'factory_id'] as $f) {
            if (! empty($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }

        return $query;
    }

    private function commonFilters(Builder $query, array $filters, string $table): void
    {
        foreach (['factory_id', 'warehouse_id', 'location_id', 'item_id'] as $f) {
            if (! empty($filters[$f])) {
                $query->where("{$table}.{$f}", $filters[$f]);
            }
        }
        if (! empty($filters['category_id'])) {
            $query->where('inv_i.category_id', $filters['category_id']);
        }
        if (! empty($filters['item_type'])) {
            $query->where('inv_i.item_type', $filters['item_type']);
        }
        if ($term = trim((string) ($filters['search'] ?? ''))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(inv_i.code) LIKE ?', [$like])->orWhereRaw('LOWER(inv_i.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inv_l.code) LIKE ?', [$like]));
        }
    }
}
