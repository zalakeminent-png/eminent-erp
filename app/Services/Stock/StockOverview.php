<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Live stock per SKU, calculated from posted ledger entries only:
 *   Live = Opening + Inward received − Rejected − Outward
 * (equivalently Opening + accepted inward − outward). Every ledger row
 * belongs to exactly one storage location, so totals never double-count
 * parent and child locations. SKUs without movements show zero.
 */
class StockOverview
{
    public const STATUSES = ['ok' => 'OK', 'low' => 'LOW STOCK', 'not_set' => 'Reorder level not set'];

    public const SORTABLE = ['sku', 'product_name', 'category', 'material', 'opening_stock', 'total_inward', 'total_outward', 'total_rejected', 'live_balance', 'reorder_level'];

    public function __construct(private readonly StructureScope $scope) {}

    /** @param array<string, mixed> $filters */
    public function query(array $filters, User $user): Builder
    {
        $totals = DB::table('stock_ledger_entries as e')
            ->select('e.sku_id')
            ->selectRaw("SUM(CASE WHEN e.kind = 'opening' THEN e.qty_in ELSE 0 END) AS opening_stock")
            ->selectRaw("SUM(CASE WHEN e.kind = 'inward' THEN e.qty_in ELSE 0 END) AS total_inward")
            ->selectRaw('SUM(e.qty_rejected) AS total_rejected')
            ->selectRaw('SUM(e.qty_out) AS total_outward')
            ->groupBy('e.sku_id');
        $this->applyLocationFilters($totals, $filters, $user, 'e');

        $rows = DB::table('skus as s')
            ->leftJoinSub($totals, 't', 't.sku_id', '=', 's.id')
            ->where('s.company_id', Company::current()?->id)
            ->select(['s.id', 's.sku', 's.product_name', 's.category', 's.material', 's.size', 's.unit', 's.reorder_level', 's.is_active'])
            ->selectRaw('COALESCE(t.opening_stock, 0) AS opening_stock')
            ->selectRaw('COALESCE(t.total_inward, 0) AS total_inward')
            ->selectRaw('COALESCE(t.total_rejected, 0) AS total_rejected')
            ->selectRaw('COALESCE(t.total_outward, 0) AS total_outward')
            ->selectRaw('COALESCE(t.opening_stock, 0) + COALESCE(t.total_inward, 0) - COALESCE(t.total_rejected, 0) - COALESCE(t.total_outward, 0) AS live_balance');

        if (empty($filters['include_inactive'])) {
            $rows->where('s.is_active', true);
        }
        foreach (['category', 'material'] as $column) {
            if (! empty($filters[$column])) {
                $rows->where("s.{$column}", $filters[$column]);
            }
        }
        if (! empty($filters['sku_id'])) {
            $rows->where('s.id', $filters['sku_id']);
        }
        if ($term = trim((string) ($filters['search'] ?? ''))) {
            $like = Search::like($term);
            $rows->where(fn ($q) => $q->whereRaw('LOWER(s.sku) LIKE ?', [$like])->orWhereRaw('LOWER(s.product_name) LIKE ?', [$like]));
        }

        $status = "CASE WHEN o.reorder_level IS NULL THEN (CASE WHEN o.live_balance <= 0 THEN 'low' ELSE 'not_set' END)
                        WHEN o.live_balance <= o.reorder_level THEN 'low' ELSE 'ok' END";
        $outer = DB::query()->fromSub($rows, 'o')->select('o.*')->selectRaw("{$status} AS status");
        if (! empty($filters['status']) && isset(self::STATUSES[$filters['status']])) {
            $outer->whereRaw("{$status} = ?", [$filters['status']]);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'sku';

        return $outer->orderBy("o.{$sort}", ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('o.sku');
    }

    /** @return array<string, mixed> */
    public function row(object $r): array
    {
        $fmt = fn ($v) => self::number($v);

        return [
            'id' => (int) $r->id,
            'sku' => $r->sku,
            'product_name' => $r->product_name,
            'category' => $r->category,
            'material' => $r->material,
            'size' => $r->size,
            'unit' => $r->unit,
            'is_active' => (bool) $r->is_active,
            'opening_stock' => $fmt($r->opening_stock),
            'total_inward' => $fmt($r->total_inward),
            'total_rejected' => $fmt($r->total_rejected),
            'accepted_inward' => self::number(bcsub((string) $r->total_inward, (string) $r->total_rejected, 3)),
            'total_outward' => $fmt($r->total_outward),
            'live_balance' => $fmt($r->live_balance),
            'reorder_level' => $r->reorder_level === null ? null : $fmt($r->reorder_level),
            'status' => $r->status,
            'status_label' => self::STATUSES[$r->status] ?? $r->status,
        ];
    }

    /**
     * Restricts ledger rows to the user's factories and the factory/location
     * filters. A location filter includes its child locations.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyLocationFilters(Builder $query, array $filters, User $user, string $alias): void
    {
        $factories = $this->scope->factoryIds($user);
        if ($factories !== null) {
            $query->whereIn("{$alias}.factory_id", $factories ?: [0]);
        }
        if (! empty($filters['factory_id'])) {
            $query->where("{$alias}.factory_id", $filters['factory_id']);
        }
        if (! empty($filters['location_id'])) {
            $query->whereIn("{$alias}.location_id", $this->withDescendants((int) $filters['location_id']));
        }
    }

    /** @return list<int> */
    public function withDescendants(int $locationId): array
    {
        $ids = [$locationId];
        $frontier = [$locationId];
        while ($frontier) {
            $frontier = WarehouseLocation::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /** "1250.500" → "1250.5", "12.000" → "12". */
    public static function number(mixed $value): string
    {
        $raw = (string) ($value ?? '0');
        // Exact decimal strings (PostgreSQL) pass through; float sums (SQLite) are rounded.
        $s = preg_match('/^-?\d+(\.\d{1,3})?$/', $raw) ? bcadd($raw, '0', 3) : number_format(round((float) $raw, 3), 3, '.', '');
        $s = str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;

        return $s === '-0' || $s === '' ? '0' : $s;
    }
}
