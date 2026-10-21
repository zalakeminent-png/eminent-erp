<?php

namespace App\Services\Inventory;

/** Metadata for a posting (who/why/idempotency). */
final class PostingContext
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly ?int $userId,
        public readonly ?string $sourceDocumentType = null,
        public readonly ?int $sourceDocumentId = null,
        public readonly ?string $remarks = null,
        public readonly ?int $reversalOfId = null,
        /** Only stock-count reconciliation may post into locked locations. */
        public readonly bool $ignoreCountLocks = false,
        /** Future: consumption against an existing reservation. */
        public readonly bool $consumesReservations = false,
    ) {}
}
