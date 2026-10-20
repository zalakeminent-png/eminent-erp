<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\UnitOfMeasure;
use Illuminate\Validation\Rule;

class UomRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return UnitOfMeasure::class;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:12', 'regex:'.self::CODE_REGEX, Rule::unique('units_of_measure', 'code')->ignore($this->current()?->id)],
            'name' => ['required', 'string', 'max:60'],
            'dimension' => ['required', Rule::in(array_keys(config('erp.master_data.uom_dimensions')))],
            'decimal_precision' => ['required', 'integer', 'between:0,6'],
            'is_active' => $this->statusRule(),
        ];
    }
}
