<?php

namespace App\Models\Inventory;

use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $guarded = ['id', 'session_id'];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:6',
            'counted_quantity' => 'decimal:6',
            'counted_at' => 'datetime',
        ];
    }

    /** counted − system snapshot; null until counted. */
    public function variance(): ?string
    {
        return $this->counted_quantity === null
            ? null
            : Decimal::normalize(bcsub((string) $this->counted_quantity, (string) $this->system_quantity, 6));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
