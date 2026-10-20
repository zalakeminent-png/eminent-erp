<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\MaterialGrade;

class MaterialGradeRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return MaterialGrade::class;
    }

    public function rules(): array
    {
        return [
            'family_id' => ['required', 'integer', $this->activeUnlessUnchanged('material_families', 'family_id')->where('company_id', $this->companyId())],
            'code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('material_grades')],
            'name' => ['required', 'string', 'max:100'],
            'standard' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => $this->statusRule(),
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'family_id.exists' => 'Select an active material family.'];
    }
}
