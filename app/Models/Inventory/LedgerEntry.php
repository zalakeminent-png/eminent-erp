<?php

namespace App\Models\Inventory;

use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable signed stock movement in base UOM (authoritative stock record). */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inventory_ledger_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'balance_after' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'value_delta' => 'decimal:4',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable; post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are immutable; post a reversal instead.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'transaction_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
