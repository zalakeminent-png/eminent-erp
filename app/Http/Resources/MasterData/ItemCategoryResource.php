<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemCategory */
class ItemCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => Refs::coded($this->parent)),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'sku_prefix' => $this->sku_prefix,
            'default_item_type' => $this->default_item_type,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'items_count' => $this->whenCounted('items'),
            'children_count' => $this->whenCounted('children'),
            'attributes' => $this->whenLoaded('itemAttributes', fn () => $this->itemAttributes->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'data_type' => $a->data_type, 'is_required' => (bool) $a->pivot->is_required,
            ])->values()),
            ...Refs::audit($this->resource),
        ];
    }
}
