<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\ProductRouting;
use Illuminate\Validation\Rule;

class RoutingRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return ProductRouting::class;
    }

    public function rules(): array
    {
        $isNew = ! $this->current();

        return [
            'item_id' => $isNew ? ['required', 'integer', Rule::exists('items', 'id')] : ['prohibited'],
            'description' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'operations' => ['present', 'array', 'max:200'],
            'operations.*.sequence' => ['nullable', 'integer', 'between:1,9999'],
            'operations.*.process_id' => ['required', 'integer', Rule::exists('manufacturing_processes', 'id')],
            'operations.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'operations.*.machine_id' => ['nullable', 'integer', Rule::exists('machines', 'id')],
            'operations.*.setup_time' => ['nullable', $this->decimalRule(8, 4)],
            'operations.*.cycle_time' => ['nullable', $this->decimalRule(8, 4)],
            'operations.*.time_unit' => ['nullable', Rule::in(array_keys(config('erp.master_data.time_units')))],
            'operations.*.process_loss_percent' => ['nullable', $this->decimalRule(2, 3)],
            'operations.*.requires_qc' => ['nullable', 'boolean'],
            'operations.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'item_id.prohibited' => 'The product of a routing cannot be changed.'];
    }
}
