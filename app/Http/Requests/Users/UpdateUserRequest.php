<?php

namespace App\Http\Requests\Users;

/**
 * Same fields as creation; status changes go through PATCH /users/{id}/status
 * so they can be authorized and audited separately.
 */
class UpdateUserRequest extends StoreUserRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['is_active']);

        return $rules;
    }
}
