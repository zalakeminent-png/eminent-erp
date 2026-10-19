<?php

namespace App\Http\Requests\Structure;

use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class LocationRequest extends StructureRequest
{
    protected function current(): ?Model
    {
        return $this->route('location');
    }

    public function rules(): array
    {
        /** @var WarehouseLocation|null $location */
        $location = $this->current();
        $warehouseId = $location?->warehouse_id ?? $this->input('warehouse_id');
        $scopeIds = $this->scope()->factoryIds($this->user());

        $warehouseRule = Rule::exists('warehouses', 'id')->where('is_active', true);
        if ($scopeIds !== null) {
            $warehouseRule->whereIn('factory_id', $scopeIds ?: [0]);
        }

        return [
            'warehouse_id' => $location
                ? ['sometimes', 'integer', Rule::in([$location->warehouse_id])]
                : ['required', 'integer', $warehouseRule],
            // Same-warehouse, cycle and depth checks run in LocationHierarchy.
            'parent_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')],
            'code' => [
                'required', 'string', 'max:30', 'regex:'.self::CODE_REGEX,
                Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouseId)->ignore($location?->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(config('erp.structure.location_types')))],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_virtual' => ['sometimes', 'boolean'],
            'is_pickable' => ['sometimes', 'boolean'],
            'is_receivable' => ['sometimes', 'boolean'],
            'is_active' => [$location ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Select an active warehouse you have access to.',
            'warehouse_id.in' => 'A location cannot be moved to another warehouse.',
            'parent_id.exists' => 'The selected parent location does not exist.',
            'code.unique' => 'This location code is already used in the warehouse.',
            'code.regex' => 'Use uppercase letters, numbers and - _ / . only.',
            'is_active.prohibited' => 'Use the status action to activate or deactivate.',
        ];
    }
}
