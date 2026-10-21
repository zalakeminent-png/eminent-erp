<?php

namespace App\Http\Resources\Inventory;

use App\Models\WarehouseLocation;
use App\Services\Inventory\StockQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Compact references used by inventory resources. */
final class InventoryRefs
{
    /** Pagination meta in the same shape as Laravel resource collections. */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
        ];
    }

    /** @return array{id: int, code: string, name: string, warehouse_id: int, warehouse_code: ?string, purpose: string}|null */
    public static function location(?WarehouseLocation $location): ?array
    {
        if (! $location) {
            return null;
        }

        return [
            'id' => $location->id,
            'code' => $location->code,
            'name' => $location->name,
            'warehouse_id' => $location->warehouse_id,
            'warehouse_code' => $location->relationLoaded('warehouse') ? $location->warehouse?->code : null,
            'purpose' => StockQuery::purpose($location),
        ];
    }
}
