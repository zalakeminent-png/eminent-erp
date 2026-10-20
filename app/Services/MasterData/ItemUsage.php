<?php

namespace App\Services\MasterData;

use App\Models\MasterData\Bom;
use App\Models\MasterData\BomLine;
use App\Models\MasterData\Item;
use App\Models\MasterData\ProductRouting;
use App\Models\MasterData\QcSpecification;
use Illuminate\Support\Facades\DB;

/**
 * Where an item is used. Items referenced by approved engineering documents
 * or by transactions of future modules get their structural fields
 * (base UOM, item type) locked so history stays consistent.
 */
class ItemUsage
{
    /**
     * @return list<string> human-readable reasons the item is locked
     */
    public function lockReasons(Item $item): array
    {
        $reasons = [];

        $approved = fn ($query) => $query->whereNotNull('approved_at');

        if (Bom::query()->where('item_id', $item->id)->tap($approved)->exists()) {
            $reasons[] = 'it has approved BOM versions';
        }
        if (BomLine::query()->where('component_item_id', $item->id)->whereHas('bom', $approved)->exists()) {
            $reasons[] = 'it is a component of approved BOMs';
        }
        if (ProductRouting::query()->where('item_id', $item->id)->tap($approved)->exists()) {
            $reasons[] = 'it has approved routings';
        }
        if (QcSpecification::query()->where('item_id', $item->id)->tap($approved)->exists()) {
            $reasons[] = 'it has approved QC specifications';
        }
        foreach (config('erp.master_data.item_references', []) as $table => $column) {
            if (DB::table($table)->where($column, $item->id)->exists()) {
                $reasons[] = "it is referenced by {$table}";
            }
        }

        return $reasons;
    }

    /** @return array<string, int> */
    public function counts(Item $item): array
    {
        return [
            'boms' => Bom::query()->where('item_id', $item->id)->count(),
            'used_in_boms' => BomLine::query()->where('component_item_id', $item->id)->distinct()->count('bom_id'),
            'routings' => ProductRouting::query()->where('item_id', $item->id)->count(),
            'qc_specifications' => QcSpecification::query()->where('item_id', $item->id)->count(),
        ];
    }
}
