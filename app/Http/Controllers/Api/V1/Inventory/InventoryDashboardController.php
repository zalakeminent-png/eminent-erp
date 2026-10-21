<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InventoryDocument;
use App\Models\Inventory\LedgerEntry;
use App\Models\MasterData\Item;
use App\Services\Inventory\InventoryReports;
use App\Services\Inventory\StockBrowser;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory dashboard. Quantities are never summed across different units:
 * every quantity figure is a list of {uom, quantity}. Values appear only
 * where real costs exist, together with a count of unvalued balances.
 */
class InventoryDashboardController extends Controller
{
    private const GROUPS = [
        'raw_material' => 'Raw Material',
        'semi_finished' => 'Semi-Finished Goods',
        'finished_good' => 'Finished Goods',
        'consumable' => 'Consumables',
        'packing_material' => 'Packing Material',
    ];

    public function __invoke(Request $request, StockBrowser $browser, StructureScope $scope, InventoryReports $reports): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $user = $request->user();
        $filters = $request->only(['factory_id', 'department_id', 'warehouse_id', 'location_id', 'category_id']);

        $balances = fn () => $browser->balances($filters, $user)->reorder()->getQuery();
        $byUom = function (array $where) use ($balances) {
            $q = $balances();
            $q->columns = null;
            foreach ($where as $column => $values) {
                $q->whereIn($column, (array) $values);
            }

            return $q->join('units_of_measure as dash_u', 'dash_u.id', '=', 'inventory_balances.base_uom_id')
                ->selectRaw('dash_u.code as uom, SUM(inventory_balances.quantity) as quantity, COUNT(DISTINCT inventory_balances.item_id) as skus,
                    SUM(CASE WHEN inventory_balances.value IS NULL THEN 0 ELSE inventory_balances.value END) as value,
                    SUM(CASE WHEN inventory_balances.value IS NULL THEN 1 ELSE 0 END) as unvalued')
                ->groupBy('dash_u.code')->orderBy('dash_u.code')->get()
                ->map(fn ($r) => ['uom' => $r->uom, 'quantity' => Decimal::normalize((string) $r->quantity), 'skus' => (int) $r->skus,
                    'value' => Decimal::normalize((string) $r->value), 'unvalued_balances' => (int) $r->unvalued]);
        };
        $sumValue = fn ($rows) => $rows->reduce(fn ($c, $r) => bcadd($c, $r['value'], 4), '0');

        $groups = collect(self::GROUPS)->map(function ($label, $type) use ($byUom, $sumValue) {
            $rows = $byUom(['inv_i.item_type' => $type]);

            return ['key' => $type, 'label' => $label, 'skus' => $rows->sum('skus'), 'quantities' => $rows->values(),
                'known_value' => Decimal::normalize($sumValue($rows)), 'unvalued_balances' => $rows->sum('unvalued_balances')];
        })->values();

        $all = $byUom([]);
        $availableSkus = (clone $balances())->where('inventory_balances.stock_status', 'available')->where('inventory_balances.quantity', '>', 0)
            ->distinct()->count('inventory_balances.item_id');

        $documentCounts = function (string $type, array $statuses) use ($scope, $user, $filters) {
            $q = InventoryDocument::query()->where('document_type', $type)->whereIn('status', $statuses);
            $scope->applyByFactory($q, $user);
            foreach (['factory_id', 'warehouse_id', 'department_id'] as $f) {
                if (! empty($filters[$f])) {
                    $q->where($f, $filters[$f]);
                }
            }

            return $q->count();
        };

        $low = $reports->build('low-stock', $filters, $user);
        $movementFilters = [...$filters, 'date_from' => $request->query('date_from'), 'date_to' => $request->query('date_to')];

        return response()->json(['data' => [
            'active_items' => Item::query()->where('is_active', true)->where('is_stock_item', true)->where('track_inventory', true)->count(),
            'items_with_available_stock' => $availableSkus,
            'valuation' => [
                'known_value' => Decimal::normalize($sumValue($all)),
                'unvalued_balances' => $all->sum('unvalued_balances'),
            ],
            'stock_by_uom' => $all->values(),
            'groups' => $groups,
            'rejected' => $byUom(['inventory_balances.stock_status' => 'rejected'])->values(),
            'scrap' => $byUom(['inventory_balances.stock_status' => 'scrap'])->values(),
            'qc_pending' => $byUom(['inventory_balances.stock_status' => ['qc_pending', 'qc_hold']])->values(),
            'low_stock' => [
                'count' => (clone $low['query'])->count(),
                'items' => (clone $low['query'])->limit(10)->get()->map($low['map']),
            ],
            'recent_movements' => $browser->ledger($movementFilters, $user)->limit(10)->get()->map(fn (LedgerEntry $e) => $browser->ledgerRow($e)),
            'pending' => [
                'goods_receipts' => $documentCounts(InventoryDocument::GRN, ['draft', 'submitted']),
                'transfers' => $documentCounts(InventoryDocument::TRANSFER, ['submitted', 'approved', 'in_transit', 'partially_received']),
                'material_issues' => $documentCounts(InventoryDocument::MATERIAL_ISSUE, ['submitted', 'approved']),
                'adjustments_awaiting_approval' => $documentCounts(InventoryDocument::ADJUSTMENT, ['submitted']),
            ],
        ]]);
    }
}
