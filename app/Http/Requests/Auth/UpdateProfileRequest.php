<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-service profile edits. Email, roles, status and employee code are
 * administrator-managed and deliberately not accepted here.
 */
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
        ];
    }
}
