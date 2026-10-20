<?php

namespace App\Http\Requests\MasterData;

use App\Models\Department;
use App\Models\MasterData\Machine;
use App\Services\Structure\StructureScope;
use Illuminate\Validation\Rule;

class MachineRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Machine::class;
    }

    public function rules(): array
    {
        $departmentId = $this->input('department_id', $this->current()?->department_id);
        $factoryId = Department::query()->whereKey($departmentId)->value('factory_id');
        $scope = app(StructureScope::class)->factoryIds($this->user());

        $departmentRule = $this->activeUnlessUnchanged('departments', 'department_id');
        if ($scope !== null) {
            $departmentRule->whereIn('factory_id', $scope ?: [0]);
        }

        return [
            'department_id' => ['required', 'integer', $departmentRule],
            'code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_REGEX,
                Rule::unique('machines', 'code')->where('factory_id', $factoryId)->ignore($this->current()?->id)],
            'name' => ['required', 'string', 'max:150'],
            'machine_type' => ['nullable', 'string', 'max:80'],
            'workstation' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'capacity_qty' => ['nullable', $this->decimalRule(14, 4)],
            'capacity_uom_id' => ['nullable', 'required_with:capacity_qty', 'integer', $this->activeUnlessUnchanged('units_of_measure', 'capacity_uom_id')],
            'capacity_period' => ['nullable', 'required_with:capacity_qty', Rule::in(array_keys(config('erp.master_data.capacity_periods')))],
            'capacity_period_hours' => ['nullable', 'required_with:capacity_qty', 'numeric', 'gt:0', 'max:24'],
            'working_hours_per_day' => ['nullable', 'numeric', 'gt:0', 'max:24'],
            'process_ids' => ['sometimes', 'array', 'max:100'],
            'process_ids.*' => ['integer', 'distinct', Rule::exists('manufacturing_processes', 'id')->where('is_active', true)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'is_active' => $this->statusRule(),
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'code.unique' => 'This machine code is already used in the factory.',
            'department_id.exists' => 'Select an active department you have access to.',
            'capacity_qty.regex' => 'Enter a capacity with at most 4 decimal places.',
            'capacity_uom_id.required_with' => 'Capacity needs a unit (e.g. PCS).',
            'capacity_period.required_with' => 'Capacity needs a time basis (per hour, shift or day).',
            'capacity_period_hours.required_with' => 'Give the length of the capacity period in hours (e.g. 8 for a shift).',
            'process_ids.*.exists' => 'One of the selected processes is inactive or invalid.',
        ];
    }
}
