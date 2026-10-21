<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Balance;
use App\Models\Inventory\Reservation;
use App\Models\WarehouseLocation;
use App\Support\Decimal;

/**
 * Read-side stock figures. Quantities are per item in its base UOM, so they
 * are never summed across different items/units.
 */
class StockQuery
{
    private const SCALE = 6;

    public const NON_RESERVABLE_PURPOSES = ['qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap', 'in_transit'];

    public function onHand(int $itemId, int $locationId, string $status = 'available'): string
    {
        return $this->sum(Balance::query()->where('item_id', $itemId)->where('location_id', $locationId)->where('stock_status', $status), 'quantity');
    }

    public function locationReserved(int $itemId, int $locationId): string
    {
        return $this->openReserved(Reservation::query()->holding()->where('item_id', $itemId)->where('location_id', $locationId));
    }

    public function warehouseReserved(int $itemId, int $warehouseId): string
    {
        return $this->openReserved(Reservation::query()->holding()->where('item_id', $itemId)->where('warehouse_id', $warehouseId));
    }

    /** Available at a location minus reservations made against that location. */
    public function locationAtp(int $itemId, int $locationId): string
    {
        return bcsub($this->onHand($itemId, $locationId), $this->locationReserved($itemId, $locationId), self::SCALE);
    }

    /** Available across a warehouse (eligible locations only) minus all its reservations. */
    public function warehouseAtp(int $itemId, int $warehouseId): string
    {
        $available = $this->sum(Balance::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->whereIn('stock_status', config('erp.inventory.reservable_statuses'))
            ->whereNotIn('location_id', $this->ineligibleLocationIds($warehouseId)), 'quantity');

        return bcsub($available, $this->warehouseReserved($itemId, $warehouseId), self::SCALE);
    }

    /**
     * Locations whose purpose makes their stock ineligible for reservation
     * (QC, rework, rejected, scrap, in transit), whatever its status.
     *
     * @return list<int>
     */
    public function ineligibleLocationIds(int $warehouseId): array
    {
        $blocked = self::NON_RESERVABLE_PURPOSES;

        return WarehouseLocation::query()->with('warehouse:id,stock_purpose')->where('warehouse_id', $warehouseId)->get(['id', 'warehouse_id', 'stock_purpose'])
            ->filter(fn (WarehouseLocation $l) => in_array(self::purpose($l), $blocked, true))
            ->pluck('id')->all();
    }

    public static function isReservableLocation(WarehouseLocation $location): bool
    {
        return ! in_array(self::purpose($location), self::NON_RESERVABLE_PURPOSES, true);
    }

    /** Available-to-promise for a new reservation (location if given, else warehouse). */
    public function atp(int $itemId, int $warehouseId, ?int $locationId = null): string
    {
        $warehouse = $this->warehouseAtp($itemId, $warehouseId);
        if ($locationId === null) {
            return $warehouse;
        }
        $location = $this->locationAtp($itemId, $locationId);

        return bccomp($location, $warehouse, self::SCALE) < 0 ? $location : $warehouse;
    }

    /** Per-status quantities + reserved + ATP for one item (optionally one location). */
    public function summary(int $itemId, ?int $warehouseId = null, ?int $locationId = null): array
    {
        $balances = Balance::query()->where('item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get(['stock_status', 'quantity']);

        $byStatus = array_fill_keys(array_keys(config('erp.inventory.stock_statuses')), '0');
        foreach ($balances as $b) {
            $byStatus[$b->stock_status] = Decimal::normalize(bcadd($byStatus[$b->stock_status], (string) $b->quantity, self::SCALE));
        }
        $physical = '0';
        foreach ($byStatus as $qty) {
            $physical = bcadd($physical, $qty, self::SCALE);
        }

        $reserved = $this->openReserved(Reservation::query()->holding()->where('item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId)));

        return [
            'physical' => Decimal::normalize($physical),
            'by_status' => $byStatus,
            'reserved' => $reserved,
            'available_to_promise' => Decimal::normalize(bcsub($byStatus['available'], $reserved, self::SCALE)),
        ];
    }

    /** Effective stock purpose of a location (location → warehouse → general). */
    public static function purpose(WarehouseLocation $location): string
    {
        return $location->stock_purpose ?? $location->warehouse?->stock_purpose ?? 'general';
    }

    private function openReserved($query): string
    {
        $total = '0';
        foreach ($query->get(['base_quantity', 'released_base_quantity']) as $r) {
            $total = bcadd($total, bcsub((string) $r->base_quantity, (string) $r->released_base_quantity, self::SCALE), self::SCALE);
        }

        return Decimal::normalize($total);
    }

    private function sum($query, string $column): string
    {
        $total = '0';
        foreach ($query->pluck($column) as $value) {
            $total = bcadd($total, (string) $value, self::SCALE);
        }

        return Decimal::normalize($total);
    }
}
