<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\MaterialFamily;

class MaterialFamilyRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return MaterialFamily::class;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('material_families')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => $this->statusRule(),
        ];
    }
}
