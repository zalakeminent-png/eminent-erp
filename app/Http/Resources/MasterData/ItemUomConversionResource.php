<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\ItemUomConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemUomConversion */
class ItemUomConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'uom_id' => $this->uom_id,
            'uom' => Refs::uom($this->uom),
            'factor' => Refs::decimal($this->factor),
            'remarks' => $this->remarks,
            'is_active' => $this->is_active,
        ];
    }
}
