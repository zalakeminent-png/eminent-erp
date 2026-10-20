<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\QcParameter;
use Illuminate\Validation\Rule;

class QcParameterRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return QcParameter::class;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('qc_parameters')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'data_type' => ['required', Rule::in(array_keys(config('erp.master_data.qc_data_types')))],
            'default_uom_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('units_of_measure', 'default_uom_id')],
            'options' => ['required_if:data_type,selection', 'nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'is_active' => $this->statusRule(),
        ];
    }
}
