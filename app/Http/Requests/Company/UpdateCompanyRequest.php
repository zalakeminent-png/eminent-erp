<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompanyRequest extends FormRequest
{
    public const GSTIN_REGEX = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const PAN_REGEX = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const CIN_REGEX = '/^[LU][0-9]{5}[A-Z]{2}[0-9]{4}[A-Z]{3}[0-9]{6}$/';

    protected function prepareForValidation(): void
    {
        $upper = fn ($value) => is_string($value) && $value !== '' ? strtoupper(trim($value)) : $value;

        $this->merge([
            'code' => $upper($this->input('code')),
            'gstin' => $upper($this->input('gstin')),
            'pan' => $upper($this->input('pan')),
            'cin' => $upper($this->input('cin')),
            'currency' => $upper($this->input('currency')),
        ]);
    }

    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company') ?? Company::current();
        $isIndia = strcasecmp((string) $this->input('country'), 'India') === 0;

        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('companies', 'code')->ignore($company?->id)],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.self::GSTIN_REGEX, Rule::unique('companies', 'gstin')->ignore($company?->id)],
            'pan' => ['nullable', 'string', 'size:10', 'regex:'.self::PAN_REGEX],
            'cin' => ['nullable', 'string', 'size:21', 'regex:'.self::CIN_REGEX],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => $isIndia
                ? ['nullable', 'string', 'regex:/^[1-9][0-9]{5}$/']
                : ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9\s\-]+$/'],
            'country' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'financial_year_start_month' => ['required', 'integer', 'between:1,12'],
            'financial_year_end_month' => ['required', 'integer', 'between:1,12'],
            'is_active' => ['sometimes', 'boolean'],
            'logo' => [
                'nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp',
                'max:'.config('erp.company.logo_max_kb'),
                'dimensions:max_width=4000,max_height=4000',
            ],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $start = (int) $this->input('financial_year_start_month');
                $end = (int) $this->input('financial_year_end_month');
                if ($start >= 1 && $end >= 1 && $end !== (($start + 10) % 12) + 1) {
                    $validator->errors()->add(
                        'financial_year_end_month',
                        'The financial year must span 12 months: the end month must be the month before the start month.',
                    );
                }

                $gstin = $this->input('gstin');
                $pan = $this->input('pan');
                if ($gstin && $pan && ! $validator->errors()->hasAny(['gstin', 'pan']) && substr($gstin, 2, 10) !== $pan) {
                    $validator->errors()->add('gstin', 'The GSTIN does not match the PAN (characters 3–12 must equal the PAN).');
                }

                $company = Company::current();
                $otherActive = Company::query()->where('is_active', true)->whereKeyNot($company?->id)->exists();
                if ($this->has('is_active') && ! $this->boolean('is_active') && ! $otherActive) {
                    $validator->errors()->add('is_active', 'The operating company cannot be deactivated while it is the only active company.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'gstin.regex' => 'The GSTIN format is invalid (e.g. 27AAPFU0939F1ZV).',
            'pan.regex' => 'The PAN format is invalid (e.g. AAPFU0939F).',
            'cin.regex' => 'The CIN format is invalid (e.g. U12345MH2020PTC123456).',
            'postal_code.regex' => 'The postal code is invalid.',
            'phone.regex' => 'The phone number is invalid.',
            'logo.max' => 'The logo may not be larger than :max KB.',
        ];
    }
}
