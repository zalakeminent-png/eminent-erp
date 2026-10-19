<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'factory_id' => $this->factory_id,
            'department_id' => $this->department_id,
            'factory' => $this->whenLoaded('primaryFactory', fn () => $this->primaryFactory ? [
                'id' => $this->primaryFactory->id, 'code' => $this->primaryFactory->code, 'name' => $this->primaryFactory->name,
            ] : null),
            'department' => $this->whenLoaded('primaryDepartment', fn () => $this->primaryDepartment ? [
                'id' => $this->primaryDepartment->id, 'code' => $this->primaryDepartment->code, 'name' => $this->primaryDepartment->name,
                'factory_id' => $this->primaryDepartment->factory_id,
            ] : null),
            'additional_departments' => $this->whenLoaded('additionalDepartments', fn () => $this->additionalDepartments->map(fn ($d) => [
                'id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'factory_id' => $d->factory_id,
            ])->values()),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_active' => $role->is_active,
            ])->values()),
        ];
    }
}
