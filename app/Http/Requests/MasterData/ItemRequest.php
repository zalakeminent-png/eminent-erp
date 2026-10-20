<?php

namespace App\Http\Requests\MasterData;

use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Support\Decimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Item master validation. Rules are exposed via rulesFor() so the Excel
 * importer validates rows exactly like the API.
 */
class ItemRequest extends MasterRequest
{
    protected function modelClass(): string
    {
        return Item::class;
    }

    public function rules(): array
    {
        return [
            ...self::rulesFor($this->current(), $this->companyId(), $this->input('default_warehouse_id')),
            'is_active' => $this->statusRule(),
            'revision_reason' => ['nullable', 'string', 'max:255'],
            'uom_conversions' => [$this->current() ? 'prohibited' : 'sometimes', 'array', 'max:20'],
            'uom_conversions.*.uom_id' => ['required', 'integer', 'distinct', Rule::exists('units_of_measure', 'id')->where('is_active', true)],
            'uom_conversions.*.factor' => ['required', $this->decimalRule(18, 12)],
            'uom_conversions.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public static function rulesFor(?Item $item, ?int $companyId, mixed $warehouseId): array
    {
        $active = function (string $table, string $attribute) use ($item) {
            $current = $item?->getAttribute($attribute);

            return Rule::exists($table, 'id')->where(function ($q) use ($current) {
                $q->where('is_active', true);
                if ($current !== null) {
                    $q->orWhere('id', $current);
                }
            });
        };
        $decimal = fn (int $int, int $frac) => 'regex:/^\d{1,'.$int.'}(\.\d{1,'.$frac.'})?$/';
        $codeRequired = ! $item && config('erp.master_data.sku.mode') === 'manual';

        return [
            'code' => [$item || $codeRequired ? 'required' : 'nullable', 'string', 'max:50', 'regex:'.self::CODE_REGEX,
                Rule::unique('items', 'code')->where('company_id', $companyId)->ignore($item?->id)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', $active('item_categories', 'category_id')->where('company_id', $companyId)],
            'item_type' => ['nullable', Rule::in(array_keys(config('erp.master_data.item_types')))],
            'material_grade_id' => ['nullable', 'integer', $active('material_grades', 'material_grade_id')->where('company_id', $companyId)],
            'base_uom_id' => ['required', 'integer', $active('units_of_measure', 'base_uom_id')],
            'purchase_uom_id' => ['nullable', 'integer', $active('units_of_measure', 'purchase_uom_id')],
            'production_uom_id' => ['nullable', 'integer', $active('units_of_measure', 'production_uom_id')],
            'technical_specification' => ['nullable', 'string', 'max:10000'],
            'material' => ['nullable', 'string', 'max:100'],
            'shape' => ['nullable', 'string', 'max:60'],
            'size' => ['nullable', 'string', 'max:60'],
            'length' => ['nullable', $decimal(14, 4)],
            'width' => ['nullable', $decimal(14, 4)],
            'height' => ['nullable', $decimal(14, 4)],
            'diameter' => ['nullable', $decimal(14, 4)],
            'thickness' => ['nullable', $decimal(14, 4)],
            'dimension_uom_id' => ['nullable', 'integer', $active('units_of_measure', 'dimension_uom_id')->where('dimension', 'length')],
            'surface_finish' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:60'],
            'weight_per_piece' => ['nullable', $decimal(12, 6)],
            'weight_uom_id' => ['nullable', 'required_with:weight_per_piece', 'integer', $active('units_of_measure', 'weight_uom_id')->where('dimension', 'mass')],
            'customer_part_number' => ['nullable', 'string', 'max:80'],
            'internal_part_number' => ['nullable', 'string', 'max:80'],
            'drawing_number' => ['nullable', 'string', 'max:80'],
            'drawing_revision' => ['nullable', 'string', 'max:20'],
            'additional_specifications' => ['nullable', 'string', 'max:10000'],
            'is_stock_item' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'min_stock_level' => ['nullable', $decimal(14, 4)],
            'max_stock_level' => ['nullable', $decimal(14, 4)],
            'reorder_level' => ['nullable', $decimal(14, 4)],
            'default_warehouse_id' => ['nullable', 'integer', $active('warehouses', 'default_warehouse_id')],
            'default_location_id' => ['nullable', 'integer',
                $active('warehouse_locations', 'default_location_id')->where('warehouse_id', $warehouseId ?: 0)],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'requires_qc' => ['sometimes', 'boolean'],
            'is_purchased' => ['sometimes', 'boolean'],
            'is_manufactured' => ['sometimes', 'boolean'],
            'is_sold' => ['sometimes', 'boolean'],
            'attributes' => ['sometimes', 'array', 'max:200'],
            'attributes.*' => ['nullable'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => self::crossFieldChecks($v, $this->all())];
    }

    /** Checks shared with the importer. */
    public static function crossFieldChecks(Validator $validator, array $data): void
    {
        $errors = $validator->errors();
        $dimensions = ['length', 'width', 'height', 'diameter', 'thickness'];
        if (collect($dimensions)->contains(fn ($f) => ($data[$f] ?? null) !== null && $data[$f] !== '') && empty($data['dimension_uom_id'])) {
            $errors->add('dimension_uom_id', 'Select the unit used for the dimensions (e.g. MM).');
        }

        $min = $data['min_stock_level'] ?? null;
        $max = $data['max_stock_level'] ?? null;
        $reorder = $data['reorder_level'] ?? null;
        $num = fn ($v) => $v !== null && $v !== '' && Decimal::isNumeric((string) $v);
        if ($num($min) && $num($max) && Decimal::cmp((string) $min, (string) $max) > 0) {
            $errors->add('max_stock_level', 'Maximum stock must be at least the minimum stock.');
        }
        if ($num($reorder) && $num($max) && Decimal::cmp((string) $reorder, (string) $max) > 0) {
            $errors->add('reorder_level', 'The reorder level cannot exceed the maximum stock.');
        }
        if (! empty($data['default_location_id']) && empty($data['default_warehouse_id'])) {
            $errors->add('default_location_id', 'Select the default warehouse for this location.');
        }

        // Stock levels must respect the base unit's precision.
        if (! empty($data['base_uom_id']) && ($uom = UnitOfMeasure::find($data['base_uom_id']))) {
            foreach (['min_stock_level', 'max_stock_level', 'reorder_level'] as $field) {
                if ($num($data[$field] ?? null) && Decimal::decimals((string) $data[$field]) > $uom->decimal_precision) {
                    $errors->add($field, "{$uom->code} allows at most {$uom->decimal_precision} decimal places.");
                }
            }
        }
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'code.unique' => 'This item code / SKU already exists.',
            'category_id.exists' => 'Select an active category.',
            'material_grade_id.exists' => 'Select an active material grade.',
            'base_uom_id.exists' => 'Select an active unit.',
            'dimension_uom_id.exists' => 'Select an active length unit.',
            'weight_uom_id.exists' => 'Select an active weight unit.',
            'weight_uom_id.required_with' => 'Select the unit for the weight per piece.',
            'default_location_id.exists' => 'The location must be an active location of the selected warehouse.',
            'default_warehouse_id.exists' => 'Select an active warehouse.',
        ];
    }
}
