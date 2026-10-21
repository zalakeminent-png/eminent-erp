<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One atomic stock posting (header). Immutable: corrections are made with
 * a reversal transaction (reversal_of_id), never by editing.
 */
class InventoryTransaction extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_number', 'transaction_type', 'idempotency_key', 'source_document_type', 'source_document_id',
        'reversal_of_id', 'remarks', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory transactions are immutable.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
