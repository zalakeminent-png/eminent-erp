<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Universal conversion: 1 from_uom = factor × to_uom. */
class UomConversion extends Model
{
    use TracksAuthors;

    protected $fillable = ['from_uom_id', 'to_uom_id', 'factor', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fromUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'from_uom_id');
    }

    public function toUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'to_uom_id');
    }
}
