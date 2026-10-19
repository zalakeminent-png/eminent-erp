<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'employee_code' => is_string($this->input('employee_code')) && trim($this->input('employee_code')) !== ''
                ? strtoupper(trim($this->input('employee_code')))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'employee_code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9_\-\/]+$/', Rule::unique('users', 'employee_code')->ignore($this->route('user'))],
            'mobile' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')->where('is_active', true)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
            'employee_code.unique' => 'This employee code is already assigned to another user.',
            'role_ids.required' => 'Assign at least one role.',
            'role_ids.*.exists' => 'One of the selected roles does not exist or is inactive.',
            'mobile.regex' => 'The mobile number is invalid.',
        ];
    }
}
