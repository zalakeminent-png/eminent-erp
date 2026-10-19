<?php

namespace App\Http\Requests\Roles;

/**
 * Role details only; permissions are managed via PUT /roles/{id}/permissions.
 */
class UpdateRoleRequest extends StoreRoleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['permissions'], $rules['permissions.*']);

        return $rules;
    }
}
