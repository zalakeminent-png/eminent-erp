<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\ManufacturingProcess;
use Illuminate\Validation\Rule;

class ProcessRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return ManufacturingProcess::class;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('manufacturing_processes')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'process_type' => ['required', Rule::in(ManufacturingProcess::TYPES)],
            'default_department_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('departments', 'default_department_id')],
            'requires_qc' => ['sometimes', 'boolean'],
            'is_active' => $this->statusRule(),
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'default_department_id.exists' => 'Select an active department.'];
    }
}
