<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\Bom;
use Illuminate\Validation\Rule;

class BomRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Bom::class;
    }

    public function rules(): array
    {
        $isNew = ! $this->current();

        return [
            'item_id' => $isNew ? ['required', 'integer', Rule::exists('items', 'id')] : ['prohibited'],
            'output_qty' => ['required', $this->decimalRule(12, 6), 'not_in:0'],
            'output_uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.component_item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'lines.*.quantity' => ['required', $this->decimalRule(12, 6)],
            'lines.*.uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')],
            'lines.*.scrap_percent' => ['nullable', $this->decimalRule(2, 3)],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'item_id.prohibited' => 'The product of a BOM cannot be changed.',
            'output_qty.regex' => 'Enter a positive output quantity (max 6 decimals).',
            'output_qty.not_in' => 'Output quantity must be greater than zero.',
            'lines.*.quantity.regex' => 'Enter a positive quantity (max 6 decimals).',
            'lines.*.scrap_percent.regex' => 'Scrap % must be between 0 and 99.999.',
        ];
    }
}
