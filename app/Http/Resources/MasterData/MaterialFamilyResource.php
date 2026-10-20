<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\MaterialFamily;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialFamily */
class MaterialFamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'grades_count' => $this->whenCounted('grades'),
            ...Refs::audit($this->resource),
        ];
    }
}
