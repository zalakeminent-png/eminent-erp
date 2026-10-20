<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\UomConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UomConversion */
class UomConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_uom_id' => $this->from_uom_id,
            'to_uom_id' => $this->to_uom_id,
            'from_uom' => Refs::uom($this->fromUom),
            'to_uom' => Refs::uom($this->toUom),
            'factor' => Refs::decimal($this->factor),
            'is_active' => $this->is_active,
            ...Refs::audit($this->resource),
        ];
    }
}
