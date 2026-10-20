<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    use BelongsToCompany, TracksAuthors;

    protected $fillable = ['parent_id', 'code', 'name', 'description', 'sku_prefix', 'default_item_type', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    public function itemAttributes(): BelongsToMany
    {
        return $this->belongsToMany(ItemAttribute::class, 'item_category_attribute', 'category_id', 'attribute_id')
            ->withPivot('is_required')->withTimestamps();
    }

    /** @return list<int> this category and its ancestors, nearest first */
    public function lineageIds(): array
    {
        $ids = [];
        $cursor = $this;
        while ($cursor && count($ids) < 50) {
            $ids[] = $cursor->id;
            $cursor = $cursor->parent;
        }

        return $ids;
    }

    /** SKU prefix, inherited from the nearest ancestor that defines one. */
    public function resolvedSkuPrefix(): ?string
    {
        $cursor = $this;
        for ($i = 0; $cursor && $i < 50; $i++) {
            if ($cursor->sku_prefix) {
                return $cursor->sku_prefix;
            }
            $cursor = $cursor->parent;
        }

        return null;
    }

    public function resolvedItemType(): ?string
    {
        $cursor = $this;
        for ($i = 0; $cursor && $i < 50; $i++) {
            if ($cursor->default_item_type) {
                return $cursor->default_item_type;
            }
            $cursor = $cursor->parent;
        }

        return null;
    }
}
