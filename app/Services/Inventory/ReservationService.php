<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Balance;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\ReservationEvent;
use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\MasterData\NumberSequence;
use App\Services\MasterData\UomConverter;
use App\Services\Structure\DefaultWarehouse;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reservations allocate available stock logically (no ledger movement).
 * New reservations are checked against available-to-promise while the
 * item's available balance rows are locked, so two concurrent requests
 * cannot over-allocate the same stock.
 */
class ReservationService
{
    public const REFERENCE_TYPES = ['manual', 'sales_order', 'production_order', 'dispatch'];

    public function __construct(
        private readonly StockQuery $query,
        private readonly UomConverter $converter,
        private readonly NumberSequence $numbers,
        private readonly StructureScope $scope,
        private readonly ActivityLogger $logger,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function reserve(array $data, User $user): Reservation
    {
        if (! empty($data['idempotency_key']) && ($existing = Reservation::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }

        $item = Item::query()->with('baseUom')->findOrFail($data['item_id']);
        // Warehouses are internal: a reservation is for a location, or for a
        // factory as a whole (its internal storage area).
        $location = null;
        if (! empty($data['location_id'])) {
            $location = WarehouseLocation::query()->findOrFail($data['location_id']);
            $warehouse = Warehouse::query()->findOrFail($location->warehouse_id);
        } elseif (! empty($data['factory_id'])) {
            $this->scope->ensureFactory($user, (int) $data['factory_id']);
            $warehouse = app(DefaultWarehouse::class)->for((int) $data['factory_id']);
        } else {
            $warehouse = Warehouse::query()->findOrFail($data['warehouse_id'] ?? 0);
        }
        $this->scope->ensureFactory($user, $warehouse->factory_id);
        if ($location) {
            if (! StockQuery::isReservableLocation($location->setRelation('warehouse', $warehouse))) {
                throw ValidationException::withMessages(['location_id' => "Stock at {$location->code} (QC / rework / rejected / scrap) cannot be reserved."]);
            }
        }
        $uom = UnitOfMeasure::query()->findOrFail($data['uom_id']);
        $converted = $this->converter->convert((string) $data['quantity'], $uom, $item->baseUom, $item);

        try {
            return DB::transaction(function () use ($data, $user, $item, $warehouse, $location, $uom, $converted) {
                // Serialise against postings and other reservations for this item.
                Balance::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)
                    ->whereIn('stock_status', config('erp.inventory.reservable_statuses'))->orderBy('id')->lockForUpdate()->get();

                $atp = $this->query->atp($item->id, $warehouse->id, $location?->id);
                if (bccomp($converted['quantity'], $atp, 6) > 0) {
                    throw ValidationException::withMessages(['quantity' => sprintf(
                        'Only %s %s of %s is available to reserve%s.',
                        Decimal::normalize($atp), $item->baseUom->code, $item->code, $location ? " at {$location->code}" : ' in this factory',
                    )]);
                }

                $reservation = new Reservation([
                    'reservation_number' => $this->numbers->formatted('inventory-reservation', 'RSV-', 6),
                    'factory_id' => $warehouse->factory_id,
                    'warehouse_id' => $warehouse->id,
                    'location_id' => $location?->id,
                    'item_id' => $item->id,
                    'quantity' => $data['quantity'],
                    'uom_id' => $uom->id,
                    'conversion_factor' => $converted['factor'],
                    'base_quantity' => $converted['quantity'],
                    'released_base_quantity' => 0,
                    'base_uom_id' => $item->base_uom_id,
                    'reference_type' => $data['reference_type'] ?? 'manual',
                    'reference_id' => $data['reference_id'] ?? null,
                    'reference_number' => $data['reference_number'] ?? null,
                    'status' => Reservation::ACTIVE,
                    'expires_on' => $data['expires_on'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'reserved_by' => $user->id,
                ]);
                $reservation->save();
                $this->event($reservation, 'created', $converted['quantity'], $data['remarks'] ?? null, $user);
                $this->logger->log('inventory.reservation.created', $reservation, [
                    'number' => $reservation->reservation_number, 'item' => $item->code, 'base_quantity' => $converted['quantity'],
                ], "Reserved {$converted['quantity']} {$item->baseUom->code} of {$item->code}", $user);

                return $reservation->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! empty($data['idempotency_key']) && ($existing = Reservation::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Releases all (null) or part of the open quantity (in base UOM). */
    public function release(Reservation $reservation, ?string $baseQuantity, ?string $remarks, User $user, bool $cancel = false): Reservation
    {
        $this->scope->ensureFactory($user, $reservation->factory_id);

        return DB::transaction(function () use ($reservation, $baseQuantity, $remarks, $user, $cancel) {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($reservation->status, Reservation::OPEN, true)) {
                throw new ConflictHttpException("Reservation {$reservation->reservation_number} is {$reservation->status}.");
            }
            $open = $reservation->openBaseQuantity();
            $qty = $baseQuantity ?? $open;
            if (! Decimal::isPositive($qty) || Decimal::decimals($qty) > 6 || bccomp($qty, $open, 6) > 0) {
                throw ValidationException::withMessages(['base_quantity' => "Enter a quantity between 0 and the open {$open}."]);
            }

            $released = bcadd(Decimal::normalize((string) $reservation->released_base_quantity), $qty, 6);
            $full = bccomp($released, Decimal::normalize((string) $reservation->base_quantity), 6) >= 0;
            $reservation->released_base_quantity = $released;
            $reservation->status = $full ? ($cancel ? Reservation::CANCELLED : Reservation::RELEASED) : Reservation::PARTIALLY_RELEASED;
            $reservation->save();

            $action = $cancel ? 'cancelled' : 'released';
            $this->event($reservation, $action, $qty, $remarks, $user);
            $this->logger->log("inventory.reservation.{$action}", $reservation, ['number' => $reservation->reservation_number, 'base_quantity' => $qty], null, $user);

            return $reservation->refresh();
        });
    }

    /** Marks reservations past their expiry date as expired (scheduled). */
    public function expire(): int
    {
        $count = 0;
        Reservation::query()->whereIn('status', Reservation::OPEN)->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now()->toDateString())->each(function (Reservation $r) use (&$count) {
                $r->forceFill(['status' => Reservation::EXPIRED])->save();
                $this->event($r, 'expired', $r->openBaseQuantity(), 'Expired automatically', null);
                $count++;
            });

        return $count;
    }

    private function event(Reservation $reservation, string $action, ?string $qty, ?string $remarks, ?User $user): void
    {
        ReservationEvent::create([
            'reservation_id' => $reservation->id, 'action' => $action, 'base_quantity' => $qty,
            'remarks' => $remarks ? mb_substr($remarks, 0, 500) : null, 'user_id' => $user?->id,
        ]);
    }
}
