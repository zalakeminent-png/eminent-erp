<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Balance;
use App\Models\Inventory\InventoryTransaction;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\StockCountSession;
use App\Models\MasterData\Item;
use App\Models\WarehouseLocation;
use App\Services\MasterData\NumberSequence;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of stock. Every physical movement becomes immutable,
 * signed ledger entries; cached balances are updated in the same database
 * transaction under row locks (taken in a fixed order to avoid deadlocks).
 *
 * Guarantees:
 *  - idempotent: a repeated idempotency key returns the original transaction;
 *  - no unauthorised negative stock (only items flagged allow_negative_stock
 *    may go below zero, and only for "available" stock);
 *  - available stock never drops below active reservations;
 *  - no postings into locations locked by a running stock count;
 *  - exact decimal arithmetic; values only where real costs exist.
 */
class StockPostingService
{
    private const SCALE = 6;

    public function __construct(private readonly NumberSequence $numbers) {}

    /**
     * @param  list<Movement>  $movements
     */
    public function post(string $type, array $movements, PostingContext $context): InventoryTransaction
    {
        if ($existing = $this->existing($context->idempotencyKey)) {
            return $existing;
        }
        if (! $movements) {
            throw ValidationException::withMessages(['lines' => 'Nothing to post.']);
        }

        try {
            return DB::transaction(fn () => $this->apply($type, $movements, $context));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the race.
            if ($existing = $this->existing($context->idempotencyKey)) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Posts the exact opposite of a transaction (compensating entries).
     * Each transaction can be reversed at most once.
     */
    public function reverse(InventoryTransaction $original, ?int $userId, string $reason): InventoryTransaction
    {
        if ($original->reversal_of_id) {
            throw ValidationException::withMessages(['transaction' => 'A reversal cannot itself be reversed.']);
        }
        if ($done = InventoryTransaction::query()->where('reversal_of_id', $original->id)->first()) {
            return $done;
        }

        $movements = $original->entries()->orderBy('id')->get()->map(fn (LedgerEntry $e) => new Movement(
            itemId: $e->item_id,
            locationId: $e->location_id,
            status: $e->stock_status,
            quantity: Decimal::normalize(bcmul((string) $e->quantity, '-1', self::SCALE)),
            unitCost: $e->unit_cost !== null ? (string) $e->unit_cost : null,
            lineId: $e->source_document_line_id,
            counterparty: $e->counterparty,
        ))->all();

        return $this->post('reversal', $movements, new PostingContext(
            idempotencyKey: "reversal:{$original->id}",
            userId: $userId,
            sourceDocumentType: $original->source_document_type,
            sourceDocumentId: $original->source_document_id,
            remarks: "Reversal of {$original->transaction_number}: {$reason}",
            reversalOfId: $original->id,
        ));
    }

    private function existing(string $key): ?InventoryTransaction
    {
        return InventoryTransaction::query()->where('idempotency_key', $key)->first();
    }

    /** @param list<Movement> $movements */
    private function apply(string $type, array $movements, PostingContext $context): InventoryTransaction
    {
        $items = Item::query()->whereKey(collect($movements)->pluck('itemId')->unique())->get()->keyBy('id');
        $locations = WarehouseLocation::query()->with('warehouse.factory')->whereKey(collect($movements)->pluck('locationId')->unique())->get()->keyBy('id');

        $this->validate($movements, $items, $locations, $context);

        $now = now();
        $transaction = InventoryTransaction::create([
            'transaction_number' => $this->numbers->formatted('inventory-transaction', 'TXN-', 7),
            'transaction_type' => $type,
            'idempotency_key' => $context->idempotencyKey,
            'source_document_type' => $context->sourceDocumentType,
            'source_document_id' => $context->sourceDocumentId,
            'reversal_of_id' => $context->reversalOfId,
            'remarks' => $context->remarks ? mb_substr($context->remarks, 0, 500) : null,
            'created_by' => $context->userId,
            'posted_at' => $now,
        ]);

        $balances = $this->lockBalances($movements, $items, $locations);

        // Outbound first, so paired inbound movements can inherit their cost.
        $ordered = collect($movements)->sortBy(fn (Movement $m) => $m->isInbound() ? 1 : 0)->values();
        $pairCosts = [];
        $reducedAvailable = [];

        foreach ($ordered as $movement) {
            /** @var Balance $balance */
            $balance = $balances[$movement->identity()];
            $item = $items[$movement->itemId];
            $before = Decimal::normalize((string) $balance->quantity);
            $after = bcadd($before, $movement->quantity, self::SCALE);

            if (bccomp($after, '0', self::SCALE) < 0 && ! ($item->allow_negative_stock && $movement->status === 'available')) {
                $location = $locations[$movement->locationId];
                throw ValidationException::withMessages(['quantity' => sprintf(
                    'Insufficient stock: %s at %s / %s (%s) has %s %s; %s requested.',
                    $item->code, $location->warehouse->code, $location->code, $movement->status,
                    Decimal::normalize($before), $item->baseUom?->code ?? '', Decimal::normalize(ltrim($movement->quantity, '-')),
                )]);
            }

            [$unitCost, $valueDelta, $newValue] = $this->valuation($balance, $before, $after, $movement, $pairCosts);

            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'factory_id' => $balance->factory_id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $movement->locationId,
                'item_id' => $movement->itemId,
                'stock_status' => $movement->status,
                'quantity' => $movement->quantity,
                'base_uom_id' => $item->base_uom_id,
                'balance_after' => $after,
                'unit_cost' => $unitCost,
                'value_delta' => $valueDelta,
                'counterparty' => $movement->counterparty,
                'source_document_type' => $context->sourceDocumentType,
                'source_document_id' => $context->sourceDocumentId,
                'source_document_line_id' => $movement->lineId,
                'created_by' => $context->userId,
                'posted_at' => $now,
            ]);

            $balance->quantity = $after;
            $balance->value = $newValue;
            $balance->last_movement_at = $now;
            if ($movement->isInbound()) {
                $balance->last_receipt_at = $now;
            }
            $balance->save();

            if (! $movement->isInbound() && in_array($movement->status, config('erp.inventory.reservable_statuses'), true)) {
                $reducedAvailable[$movement->itemId.'|'.$movement->locationId] = [$movement->itemId, $balance->warehouse_id, $movement->locationId];
            }
        }

        if (! $context->consumesReservations) {
            $this->guardReservations($reducedAvailable, $items);
        }

        return $transaction->load('entries');
    }

    /**
     * @param  list<Movement>  $movements
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, WarehouseLocation>  $locations
     */
    private function validate(array $movements, Collection $items, Collection $locations, PostingContext $context): void
    {
        $statuses = array_keys(config('erp.inventory.stock_statuses'));
        $errors = [];

        foreach ($movements as $i => $m) {
            $item = $items->get($m->itemId);
            $location = $locations->get($m->locationId);
            if (! $item || ! $location) {
                $errors["movements.{$i}"] = 'Unknown item or location.';

                continue;
            }
            if (! in_array($m->status, $statuses, true)) {
                $errors["movements.{$i}"] = "Unknown stock status {$m->status}.";
            }
            if (! Decimal::isNumeric(ltrim($m->quantity, '-')) || bccomp($m->quantity, '0', self::SCALE) === 0 || Decimal::decimals($m->quantity) > self::SCALE) {
                $errors["movements.{$i}"] = 'Quantities must be non-zero with at most 6 decimals.';
            }
            // New stock may only enter active locations (Module 2 rule).
            if ($m->isInbound() && ! $context->reversalOfId
                && (! $location->is_active || ! $location->warehouse->is_active || ! $location->warehouse->factory->is_active)) {
                $errors["movements.{$i}"] = "Location {$location->code} or its warehouse/factory is inactive.";
            }
        }

        if (! $context->ignoreCountLocks) {
            foreach ($locations as $location) {
                if ($this->lockedByCount($location)) {
                    $errors['location'] = "Location {$location->code} is locked by a running stock count. Finish or cancel the count first.";
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function lockedByCount(WarehouseLocation $location): ?StockCountSession
    {
        return StockCountSession::query()
            ->where('warehouse_id', $location->warehouse_id)
            ->where('lock_locations', true)
            ->whereIn('status', StockCountSession::LOCKING)
            ->get()
            ->first(fn (StockCountSession $s) => $s->location_ids === null || in_array($location->id, $s->location_ids, true));
    }

    /**
     * Ensures balance rows exist and locks them in a deterministic order.
     *
     * @return array<string, Balance>
     */
    private function lockBalances(array $movements, Collection $items, Collection $locations): array
    {
        $identities = collect($movements)->keyBy(fn (Movement $m) => $m->identity())->sortKeys();
        $now = now();

        foreach ($identities as $m) {
            $location = $locations[$m->locationId];
            Balance::query()->insertOrIgnore([
                'factory_id' => $location->warehouse->factory_id,
                'warehouse_id' => $location->warehouse_id,
                'location_id' => $m->locationId,
                'item_id' => $m->itemId,
                'stock_status' => $m->status,
                'base_uom_id' => $items[$m->itemId]->base_uom_id,
                'quantity' => 0,
                'value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $locked = [];
        foreach ($identities as $key => $m) {
            $locked[$key] = Balance::query()
                ->where('item_id', $m->itemId)->where('location_id', $m->locationId)->where('stock_status', $m->status)
                ->lockForUpdate()->firstOrFail();
        }

        return $locked;
    }

    /**
     * Moving-average valuation that never invents costs. A balance whose
     * value is null has unknown valuation (e.g. stock received without cost).
     *
     * @param  array<string, ?string>  $pairCosts
     * @return array{0: ?string, 1: ?string, 2: ?string} [unit_cost, value_delta, new balance value]
     */
    private function valuation(Balance $balance, string $before, string $after, Movement $m, array &$pairCosts): array
    {
        $value = $balance->value === null ? null : Decimal::normalize((string) $balance->value);
        $wasEmpty = bccomp($before, '0', self::SCALE) === 0;

        if ($m->isInbound()) {
            $cost = $m->unitCost ?? ($m->pairKey !== null ? ($pairCosts[$m->pairKey] ?? null) : null);
            $delta = $cost !== null ? Decimal::round(bcmul($m->quantity, $cost, 10), 4) : null;
            $newValue = match (true) {
                $delta === null => null,
                $value !== null => bcadd($value, $delta, 4),
                $wasEmpty => $delta,
                default => null,
            };
        } else {
            $cost = $m->unitCost;
            if ($cost === null && $value !== null && bccomp($before, '0', self::SCALE) > 0) {
                $cost = Decimal::round(bcdiv($value, $before, 10), 6);
            }
            $delta = $cost !== null && $value !== null ? Decimal::round(bcmul($m->quantity, $cost, 10), 4) : null;
            $newValue = $delta !== null ? bcadd($value, $delta, 4) : $value;
            if ($m->pairKey !== null) {
                $pairCosts[$m->pairKey] = $delta !== null ? $cost : null;
            }
            if ($delta === null) {
                $cost = null;
            }
        }

        if (bccomp($after, '0', self::SCALE) === 0) {
            $newValue = '0'; // empty stock has a known (zero) value
        }

        return [$cost, $delta, $newValue];
    }

    /**
     * Available on-hand must stay at or above active reservations, both per
     * reserved location and per warehouse.
     *
     * @param  array<string, array{0: int, 1: int, 2: int}>  $touched
     */
    private function guardReservations(array $touched, Collection $items): void
    {
        $query = app(StockQuery::class);
        foreach ($touched as [$itemId, $warehouseId, $locationId]) {
            // Only reservations matter here; plain negative stock is checked elsewhere.
            $breachesLocation = bccomp($query->locationReserved($itemId, $locationId), '0', self::SCALE) > 0
                && bccomp($query->locationAtp($itemId, $locationId), '0', self::SCALE) < 0;
            $breachesWarehouse = bccomp($query->warehouseReserved($itemId, $warehouseId), '0', self::SCALE) > 0
                && bccomp($query->warehouseAtp($itemId, $warehouseId), '0', self::SCALE) < 0;
            if ($breachesLocation || $breachesWarehouse) {
                throw ValidationException::withMessages(['quantity' => "This would use stock of {$items[$itemId]->code} that is reserved. Release the reservation first."]);
            }
        }
    }
}
