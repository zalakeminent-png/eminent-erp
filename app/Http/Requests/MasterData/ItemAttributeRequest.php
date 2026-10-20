<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\ItemAttribute;
use Illuminate\Validation\Rule;

class ItemAttributeRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return ItemAttribute::class;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('item_attributes')],
            'name' => ['required', 'string', 'max:100'],
            'data_type' => ['required', Rule::in(ItemAttribute::DATA_TYPES)],
            'uom_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('units_of_measure', 'uom_id')],
            'options' => ['required_if:data_type,select', 'nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => $this->statusRule(),
        ];
    }
}
