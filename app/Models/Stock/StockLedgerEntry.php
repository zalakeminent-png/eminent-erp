<?php

namespace App\Models\Stock;

use App\Models\User;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only stock movement. Corrections are reversal rows, never edits. */
class StockLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_reversal' => 'boolean',
            'qty_in' => 'decimal:3',
            'qty_rejected' => 'decimal:3',
            'qty_out' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock ledger entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Stock ledger entries cannot be deleted.'));
    }

    public function document(): BelongsTo
    {
        // Deleted entries stay visible in stock history.
        return $this->belongsTo(StockDocument::class, 'document_id')->withTrashed();
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
