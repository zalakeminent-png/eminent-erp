<?php

namespace App\Http\Requests\Structure;

use App\Models\StructureType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class StructureTypeRequest extends StructureRequest
{
    protected function current(): ?Model
    {
        return $this->route('structureType');
    }

    public function rules(): array
    {
        $type = $this->current();
        $category = $type?->category ?? $this->input('category');

        return [
            'category' => $type ? ['sometimes', Rule::in([$type->category])] : ['required', Rule::in(StructureType::CATEGORIES)],
            'name' => ['required', 'string', 'max:100', Rule::unique('structure_types', 'name')->where('category', $category)->ignore($type?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'A type with this name already exists.'];
    }
}
