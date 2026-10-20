<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item-specific conversion: 1 uom = factor × the item's base UOM. */
class ItemUomConversion extends Model
{
    use TracksAuthors;

    protected $fillable = ['item_id', 'uom_id', 'factor', 'remarks', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
