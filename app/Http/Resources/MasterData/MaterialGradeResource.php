<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\MaterialGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialGrade */
class MaterialGradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'family_id' => $this->family_id,
            'family' => $this->whenLoaded('family', fn () => Refs::coded($this->family)),
            'code' => $this->code,
            'name' => $this->name,
            'standard' => $this->standard,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'items_count' => $this->whenCounted('items'),
            ...Refs::audit($this->resource),
        ];
    }
}
