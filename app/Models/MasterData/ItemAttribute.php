<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Configurable item attribute definition (e.g. "Thread Pitch", "Dome Pattern"). */
class ItemAttribute extends Model
{
    use BelongsToCompany, TracksAuthors;

    public const DATA_TYPES = ['text', 'number', 'select', 'boolean'];

    protected $fillable = ['code', 'name', 'data_type', 'uom_id', 'options', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ItemCategory::class, 'item_category_attribute', 'attribute_id', 'category_id')
            ->withPivot('is_required')->withTimestamps();
    }
}
