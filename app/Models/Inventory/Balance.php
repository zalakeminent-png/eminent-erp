<?php

namespace App\Models\Inventory;

use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached on-hand per stock identity (item + location + status). Written
 * only by StockPostingService; always reproducible from the ledger.
 */
class Balance extends Model
{
    protected $table = 'inventory_balances';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'value' => 'decimal:4',
            'last_movement_at' => 'datetime',
            'last_receipt_at' => 'datetime',
        ];
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
}
