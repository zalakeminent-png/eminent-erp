<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\QcSpecification;
use Illuminate\Validation\Rule;

class QcSpecificationRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return QcSpecification::class;
    }

    public function rules(): array
    {
        $isNew = ! $this->current();

        return [
            'item_id' => $isNew ? ['required', 'integer', Rule::exists('items', 'id')] : ['prohibited'],
            'description' => ['nullable', 'string', 'max:2000'],
            'inspection_instructions' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'lines' => ['present', 'array', 'max:300'],
            'lines.*.parameter_id' => ['required', 'integer', Rule::exists('qc_parameters', 'id')],
            'lines.*.process_id' => ['nullable', 'integer', Rule::exists('manufacturing_processes', 'id')],
            'lines.*.target_value' => ['nullable', $this->decimalRule(14, 6, true)],
            'lines.*.min_value' => ['nullable', $this->decimalRule(14, 6, true)],
            'lines.*.max_value' => ['nullable', $this->decimalRule(14, 6, true)],
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')],
            'lines.*.expected_text' => ['nullable', 'string', 'max:255'],
            'lines.*.allowed_options' => ['nullable', 'array', 'max:100'],
            'lines.*.allowed_options.*' => ['string', 'max:100'],
            'lines.*.inspection_method' => ['nullable', 'string', 'max:150'],
            'lines.*.instructions' => ['nullable', 'string', 'max:2000'],
            'lines.*.is_required' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'item_id.prohibited' => 'The item of a QC specification cannot be changed.',
            'lines.*.target_value.regex' => 'Enter a number with at most 6 decimal places.',
            'lines.*.min_value.regex' => 'Enter a number with at most 6 decimal places.',
            'lines.*.max_value.regex' => 'Enter a number with at most 6 decimal places.',
        ];
    }
}
