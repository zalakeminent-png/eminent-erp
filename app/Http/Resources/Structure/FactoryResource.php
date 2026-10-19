<?php

namespace App\Http\Resources\Structure;

use App\Models\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Factory */
class FactoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'company_id' => $this->company_id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => config("erp.structure.factory_types.{$this->type}", $this->type),
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'departments_count' => $this->whenCounted('departments'),
            'warehouses_count' => $this->whenCounted('warehouses'),
            'users_count' => $this->whenCounted('users'),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? new UserSummaryResource($this->creator) : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
