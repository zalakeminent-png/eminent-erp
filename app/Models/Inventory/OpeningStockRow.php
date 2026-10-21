<?php

namespace App\Models\Inventory;

use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningStockRow extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'opening_stock_import_rows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'errors' => 'array',
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(OpeningStockImport::class, 'import_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
