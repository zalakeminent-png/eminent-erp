<?php

namespace App\Http\Resources\Structure;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'factory_id' => $this->factory_id,
            'factory' => $this->whenLoaded('factory', fn () => [
                'id' => $this->factory->id, 'code' => $this->factory->code, 'name' => $this->factory->name, 'is_active' => $this->factory->is_active,
            ]),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id, 'code' => $this->department->code, 'name' => $this->department->name,
            ] : null),
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type ? ['id' => $this->type->id, 'name' => $this->type->name] : null),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? new UserSummaryResource($this->manager) : null),
            'is_active' => $this->is_active,
            'locations_count' => $this->whenCounted('locations'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? new UserSummaryResource($this->creator) : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
