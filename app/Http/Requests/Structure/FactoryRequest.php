<?php

namespace App\Http\Requests\Structure;

use App\Models\Company;
use App\Models\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class FactoryRequest extends StructureRequest
{
    protected function current(): ?Model
    {
        return $this->route('factory');
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->filled('company_id')) {
            $this->merge(['company_id' => $this->current()?->company_id ?? Company::current()?->id]);
        }
    }

    public function rules(): array
    {
        /** @var Factory|null $factory */
        $factory = $this->current();
        $isIndia = strcasecmp((string) $this->input('country', 'India'), 'India') === 0;

        return [
            'company_id' => ['required', 'integer', $this->activeUnlessUnchanged('companies', 'company_id')],
            'code' => [
                'required', 'string', 'max:20', 'regex:'.self::CODE_REGEX,
                Rule::unique('factories', 'code')->where('company_id', $this->input('company_id'))->ignore($factory?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(config('erp.structure.factory_types')))],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => $isIndia ? ['nullable', 'regex:/^[1-9][0-9]{5}$/'] : ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            // Status changes after creation go through PATCH .../status.
            'is_active' => [$factory ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This factory code is already used in the company.',
            'code.regex' => 'Use uppercase letters, numbers and - _ / . only.',
            'postal_code.regex' => 'Enter a valid 6-digit PIN code.',
            'contact_number.regex' => 'The contact number is invalid.',
            'is_active.prohibited' => 'Use the status action to activate or deactivate.',
        ];
    }
}
