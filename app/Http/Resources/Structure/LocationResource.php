<?php

namespace App\Http\Resources\Structure;

use App\Models\WarehouseLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WarehouseLocation */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
                'factory_id' => $this->warehouse->factory_id,
                'is_active' => $this->warehouse->is_active,
            ]),
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id, 'code' => $this->parent->code, 'name' => $this->parent->name,
            ] : null),
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => config("erp.structure.location_types.{$this->type}", $this->type),
            'description' => $this->description,
            'is_virtual' => $this->is_virtual,
            'is_pickable' => $this->is_pickable,
            'is_receivable' => $this->is_receivable,
            'is_active' => $this->is_active,
            'children_count' => $this->whenCounted('children'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? new UserSummaryResource($this->creator) : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
