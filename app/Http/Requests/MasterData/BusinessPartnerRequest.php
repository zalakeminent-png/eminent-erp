<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Models\MasterData\BusinessPartner;
use App\Models\MasterData\BusinessPartnerAddress;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BusinessPartnerRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return BusinessPartner::class;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        foreach (['gstin', 'pan', 'currency'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field))) ?: null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            // Optional: generated (BP-00001) when left blank.
            'code' => ['nullable', 'string', 'max:30', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('business_partners')],
            'name' => ['required', 'string', 'max:200'],
            'is_customer' => ['required', 'boolean'],
            'is_supplier' => ['required', 'boolean'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,20}$/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.UpdateCompanyRequest::GSTIN_REGEX],
            'pan' => ['nullable', 'string', 'size:10', 'regex:'.UpdateCompanyRequest::PAN_REGEX],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'payment_terms' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'is_active' => $this->statusRule(),

            'contacts' => ['sometimes', 'array', 'max:50'],
            'contacts.*.name' => ['required', 'string', 'max:150'],
            'contacts.*.designation' => ['nullable', 'string', 'max:100'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,20}$/'],
            'contacts.*.is_primary' => ['sometimes', 'boolean'],

            'addresses' => ['sometimes', 'array', 'max:50'],
            'addresses.*.address_type' => ['required', Rule::in(BusinessPartnerAddress::TYPES)],
            'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.address_line_1' => ['required', 'string', 'max:255'],
            'addresses.*.address_line_2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.state' => ['nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9\s\-]+$/'],
            'addresses.*.country' => ['nullable', 'string', 'max:100'],
            'addresses.*.is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->boolean('is_customer') && ! $this->boolean('is_supplier')) {
                $validator->errors()->add('is_customer', 'Mark the party as a customer, a supplier, or both.');
            }
            $gstin = $this->input('gstin');
            $pan = $this->input('pan');
            if ($gstin && $pan && ! $validator->errors()->hasAny(['gstin', 'pan']) && substr($gstin, 2, 10) !== $pan) {
                $validator->errors()->add('gstin', 'The GSTIN does not match the PAN.');
            }
            foreach (BusinessPartnerAddress::TYPES as $type) {
                $defaults = collect($this->input('addresses', []))->where('address_type', $type)->filter(fn ($a) => filter_var($a['is_default'] ?? false, FILTER_VALIDATE_BOOL));
                if ($defaults->count() > 1) {
                    $validator->errors()->add('addresses', "Only one default {$type} address is allowed.");
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'gstin.regex' => 'The GSTIN format is invalid.',
            'pan.regex' => 'The PAN format is invalid.',
            'phone.regex' => 'The phone number is invalid.',
        ];
    }
}
