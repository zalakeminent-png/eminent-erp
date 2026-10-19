<?php

namespace App\Http\Requests\Structure;

use App\Models\Department;
use App\Models\StructureType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class DepartmentRequest extends StructureRequest
{
    protected function current(): ?Model
    {
        return $this->route('department');
    }

    public function rules(): array
    {
        /** @var Department|null $department */
        $department = $this->current();
        $factoryId = $department?->factory_id ?? $this->input('factory_id');

        return [
            // A department cannot move between factories after creation.
            'factory_id' => $department
                ? ['sometimes', 'integer', Rule::in([$department->factory_id])]
                : ['required', 'integer', $this->factoryRule()],
            'type_id' => ['nullable', 'integer', $this->activeUnlessUnchanged('structure_types', 'type_id')->where('category', StructureType::DEPARTMENT)],
            'code' => [
                'required', 'string', 'max:20', 'regex:'.self::CODE_REGEX,
                Rule::unique('departments', 'code')->where('factory_id', $factoryId)->ignore($department?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Manager/supervisor may be set on create (with assign-managers);
            // afterwards they change only via PUT /departments/{id}/supervisor.
            'manager_id' => [$department ? 'prohibited' : 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'supervisor_id' => [$department ? 'prohibited' : 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'is_active' => [$department ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'factory_id.exists' => 'Select an active factory you have access to.',
            'factory_id.in' => 'A department cannot be moved to another factory.',
            'type_id.exists' => 'Select an active department type.',
            'code.unique' => 'This department code is already used in the factory.',
            'code.regex' => 'Use uppercase letters, numbers and - _ / . only.',
            'manager_id.exists' => 'The manager must be an active user.',
            'supervisor_id.exists' => 'The supervisor must be an active user.',
            'manager_id.prohibited' => 'Use the manager/supervisor assignment to change this.',
            'supervisor_id.prohibited' => 'Use the manager/supervisor assignment to change this.',
            'is_active.prohibited' => 'Use the status action to activate or deactivate.',
        ];
    }
}
