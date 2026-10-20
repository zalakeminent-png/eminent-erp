<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\ItemAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemAttribute */
class ItemAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'data_type' => $this->data_type,
            'uom_id' => $this->uom_id,
            'uom' => $this->whenLoaded('uom', fn () => Refs::uom($this->uom)),
            'options' => $this->options ?? [],
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_required' => (bool) $c->pivot->is_required,
            ])->values()),
            ...Refs::audit($this->resource),
        ];
    }
}
