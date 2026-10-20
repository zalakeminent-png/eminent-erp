<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\ManufacturingProcess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ManufacturingProcess */
class ProcessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'process_type' => $this->process_type,
            'default_department_id' => $this->default_department_id,
            'default_department' => $this->whenLoaded('defaultDepartment', fn () => Refs::coded($this->defaultDepartment)),
            'requires_qc' => $this->requires_qc,
            'is_active' => $this->is_active,
            'machines_count' => $this->whenCounted('machines'),
            ...Refs::audit($this->resource),
        ];
    }
}
