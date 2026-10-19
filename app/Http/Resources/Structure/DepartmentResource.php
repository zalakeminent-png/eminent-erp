<?php

namespace App\Http\Resources\Structure;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'factory_id' => $this->factory_id,
            'factory' => $this->whenLoaded('factory', fn () => [
                'id' => $this->factory->id, 'code' => $this->factory->code, 'name' => $this->factory->name, 'is_active' => $this->factory->is_active,
            ]),
            'type_id' => $this->type_id,
            'type' => $this->whenLoaded('type', fn () => $this->type ? ['id' => $this->type->id, 'name' => $this->type->name] : null),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? new UserSummaryResource($this->manager) : null),
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? new UserSummaryResource($this->supervisor) : null),
            'is_active' => $this->is_active,
            'members_count' => $this->whenHas('members_count'),
            'warehouses_count' => $this->whenCounted('warehouses'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? new UserSummaryResource($this->creator) : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
