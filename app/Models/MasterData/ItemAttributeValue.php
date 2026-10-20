<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemAttributeValue extends Model
{
    protected $fillable = ['item_id', 'attribute_id', 'value'];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ItemAttribute::class, 'attribute_id');
    }
}
