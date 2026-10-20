<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\ItemCategory;
use Illuminate\Validation\Rule;

class ItemCategoryRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return ItemCategory::class;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('item_categories', 'parent_id')->where('company_id', $this->companyId())],
            'code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_REGEX, $this->uniqueInCompany('item_categories')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z0-9]+$/'],
            'default_item_type' => ['nullable', Rule::in(array_keys(config('erp.master_data.item_types')))],
            'attributes' => ['sometimes', 'array', 'max:100'],
            'attributes.*.attribute_id' => ['required', 'integer', 'distinct', Rule::exists('item_attributes', 'id')->where('company_id', $this->companyId())],
            'attributes.*.is_required' => ['sometimes', 'boolean'],
            'is_active' => $this->statusRule(),
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'parent_id.exists' => 'Select an active parent category.', 'sku_prefix.regex' => 'Use uppercase letters and numbers only.'];
    }
}
