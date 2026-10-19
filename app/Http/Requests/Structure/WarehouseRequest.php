<?php

namespace App\Http\Requests\Structure;

use App\Models\StructureType;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class WarehouseRequest extends StructureRequest
{
    protected function current(): ?Model
    {
        return $this->route('warehouse');
    }

    public function rules(): array
    {
        /** @var Warehouse|null $warehouse */
        $warehouse = $this->current();
        $factoryId = $warehouse?->factory_id ?? $this->input('factory_id');

        return [
            'factory_id' => $warehouse
                ? ['sometimes', 'integer', Rule::in([$warehouse->factory_id])]
                : ['required', 'integer', $this->factoryRule()],
            // Responsible department must belong to the same factory.
            'department_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('departments', 'department_id')->where('factory_id', $factoryId ?? 0)],
            'type_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('structure_types', 'type_id')->where('category', StructureType::WAREHOUSE)],
            'code' => [
                'required', 'string', 'max:20', 'regex:'.self::CODE_REGEX,
                Rule::unique('warehouses', 'code')->where('factory_id', $factoryId)->ignore($warehouse?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'manager_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('users', 'manager_id')],
            'is_active' => [$warehouse ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'factory_id.exists' => 'Select an active factory you have access to.',
            'factory_id.in' => 'A warehouse cannot be moved to another factory.',
            'department_id.exists' => 'The responsible department must be an active department of the same factory.',
            'type_id.exists' => 'Select an active warehouse type.',
            'code.unique' => 'This warehouse code is already used in the factory.',
            'code.regex' => 'Use uppercase letters, numbers and - _ / . only.',
            'manager_id.exists' => 'The warehouse manager must be an active user.',
            'is_active.prohibited' => 'Use the status action to activate or deactivate.',
        ];
    }
}
