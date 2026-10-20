<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\QcParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QcParameter */
class QcParameterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'data_type' => $this->data_type,
            'default_uom_id' => $this->default_uom_id,
            'default_uom' => $this->whenLoaded('defaultUom', fn () => Refs::uom($this->defaultUom)),
            'options' => $this->options ?? [],
            'is_active' => $this->is_active,
            ...Refs::audit($this->resource),
        ];
    }
}
