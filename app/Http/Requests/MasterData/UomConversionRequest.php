<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\UomConversion;
use Illuminate\Validation\Rule;

class UomConversionRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return UomConversion::class;
    }

    public function rules(): array
    {
        $current = $this->current();

        return [
            'from_uom_id' => $current ? ['sometimes', Rule::in([$current->from_uom_id])] : ['required', 'integer', Rule::exists('units_of_measure', 'id')->where('is_active', true)],
            'to_uom_id' => $current ? ['sometimes', Rule::in([$current->to_uom_id])] : ['required', 'integer', Rule::exists('units_of_measure', 'id')->where('is_active', true)],
            // Up to 18 integer and 12 decimal digits — stored exactly, never rounded.
            'factor' => ['required', $this->decimalRule(18, 12)],
            'is_active' => $this->statusRule(),
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'factor.regex' => 'Enter a positive number with at most 12 decimal places.',
            'from_uom_id.in' => 'Units of an existing conversion cannot be changed; create a new conversion instead.',
            'to_uom_id.in' => 'Units of an existing conversion cannot be changed; create a new conversion instead.',
        ];
    }
}
