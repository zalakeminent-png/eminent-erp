<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\UnitOfMeasure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnitOfMeasure */
class UomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'dimension' => $this->dimension,
            'dimension_label' => config("erp.master_data.uom_dimensions.{$this->dimension}.label", $this->dimension),
            'universally_convertible' => $this->isConvertible(),
            'decimal_precision' => $this->decimal_precision,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            ...Refs::audit($this->resource),
        ];
    }
}
