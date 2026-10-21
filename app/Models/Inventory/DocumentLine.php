<?php

namespace App\Models\Inventory;

use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLine extends Model
{
    protected $table = 'inventory_document_lines';

    protected $guarded = ['id', 'document_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'system_base_quantity' => 'decimal:6',
            'received_base_quantity' => 'decimal:6',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'document_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'destination_location_id');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }
}
