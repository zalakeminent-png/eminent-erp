<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared lifecycle for BOMs, routings and QC specifications:
 * draft (editable) → approved (immutable) → inactive.
 */
trait VersionedDocument
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_INACTIVE];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** True once the document has ever been approved (approved or retired). */
    public function isFrozen(): bool
    {
        return $this->approved_at !== null;
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revisedFrom(): BelongsTo
    {
        return $this->belongsTo(static::class, 'revised_from_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::STATUS_APPROVED);
    }

    /** Approved and effective on the given date (Y-m-d). */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->approved()
            ->whereDate($this->qualifyColumn('effective_from'), '<=', $date)
            ->where(fn ($q) => $q->whereNull($this->qualifyColumn('effective_to'))->orWhereDate($this->qualifyColumn('effective_to'), '>=', $date));
    }

    /**
     * Other approved versions of the same item whose effective window
     * overlaps this one's.
     */
    public function overlappingApproved(): Builder
    {
        $from = $this->effective_from->toDateString();
        $to = $this->effective_to?->toDateString();

        return static::query()
            ->where('item_id', $this->item_id)
            ->whereKeyNot($this->getKey())
            ->approved()
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('effective_from', '<=', $to));
    }
}
