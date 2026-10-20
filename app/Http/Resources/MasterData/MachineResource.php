<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\Machine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Machine */
class MachineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'factory_id' => $this->factory_id,
            'factory' => $this->whenLoaded('factory', fn () => Refs::coded($this->factory)),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => Refs::coded($this->department)),
            'code' => $this->code,
            'name' => $this->name,
            'machine_type' => $this->machine_type,
            'workstation' => $this->workstation,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'capacity_qty' => Refs::decimal($this->capacity_qty),
            'capacity_uom_id' => $this->capacity_uom_id,
            'capacity_uom' => $this->whenLoaded('capacityUom', fn () => Refs::uom($this->capacityUom)),
            'capacity_period' => $this->capacity_period,
            'capacity_period_hours' => Refs::decimal($this->capacity_period_hours),
            'working_hours_per_day' => Refs::decimal($this->working_hours_per_day),
            'remarks' => $this->remarks,
            'is_active' => $this->is_active,
            'processes' => $this->whenLoaded('processes', fn () => $this->processes->map(fn ($p) => Refs::coded($p))->values()),
            ...Refs::audit($this->resource),
        ];
    }
}
