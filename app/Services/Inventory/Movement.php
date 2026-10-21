<?php

namespace App\Services\Inventory;

/**
 * One signed stock movement for StockPostingService, in the item's base UOM.
 *
 * - $pairKey links an outbound movement to the inbound one that receives
 *   the same goods (e.g. a transfer line): the inbound side inherits the
 *   outbound average cost when it has no explicit cost.
 */
final class Movement
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly string $status,
        public readonly string $quantity,
        public readonly ?string $unitCost = null,
        public readonly ?int $lineId = null,
        public readonly ?string $counterparty = null,
        public readonly ?string $pairKey = null,
    ) {}

    public function isInbound(): bool
    {
        return bccomp($this->quantity, '0', 6) > 0;
    }

    public function identity(): string
    {
        return sprintf('%010d|%010d|%s', $this->itemId, $this->locationId, $this->status);
    }
}
