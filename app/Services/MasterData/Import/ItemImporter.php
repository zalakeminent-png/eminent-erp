<?php

namespace App\Services\MasterData\Import;

use App\Http\Requests\MasterData\ItemRequest;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\MaterialGrade;
use App\Models\MasterData\UnitOfMeasure;
use App\Services\MasterData\ItemService;
use App\Services\MasterData\UomConverter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Item master import. Opening stock is deliberately NOT importable here —
 * it belongs to the Inventory module.
 */
class ItemImporter extends Importer
{
    private const UOM_COLUMNS = [
        'base_uom' => 'base_uom_id', 'purchase_uom' => 'purchase_uom_id', 'production_uom' => 'production_uom_id',
        'dimension_uom' => 'dimension_uom_id', 'weight_uom' => 'weight_uom_id',
    ];

    private const BOOLEAN_COLUMNS = ['is_stock_item', 'track_inventory', 'requires_qc', 'is_purchased', 'is_manufactured', 'is_sold'];

    private const TEXT_COLUMNS = [
        'description', 'technical_specification', 'material', 'shape', 'size', 'surface_finish', 'color', 'customer_part_number',
        'internal_part_number', 'drawing_number', 'drawing_revision', 'additional_specifications',
    ];

    private const DECIMAL_COLUMNS = ['length', 'width', 'height', 'diameter', 'thickness', 'weight_per_piece', 'min_stock_level', 'max_stock_level', 'reorder_level'];

    /** @var array<string, int>|null */
    private ?array $uomIds = null;

    public function entity(): string
    {
        return 'items';
    }

    public function label(): string
    {
        return 'Items / Products';
    }

    public function createPermission(): string
    {
        return 'items.create';
    }

    public function updatePermission(): string
    {
        return 'items.update';
    }

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Item Code / SKU', 'aliases' => ['code', 'sku', 'item code'], 'example' => 'FG-STUD-001'],
            'name' => ['label' => 'Item Name', 'required' => true, 'aliases' => ['name', 'item', 'description1'], 'example' => 'SS316 Tactile Stud 25mm'],
            'category_code' => ['label' => 'Category Code', 'required' => true, 'aliases' => ['category'], 'example' => 'FG'],
            'item_type' => ['label' => 'Item Type', 'aliases' => ['type'], 'example' => 'finished_good'],
            'material_grade' => ['label' => 'Material Grade Code', 'aliases' => ['grade', 'material grade'], 'example' => 'SS316'],
            'base_uom' => ['label' => 'Base UOM', 'required' => true, 'aliases' => ['uom', 'unit', 'base unit'], 'example' => 'PCS'],
            'purchase_uom' => ['label' => 'Purchase UOM', 'example' => ''],
            'production_uom' => ['label' => 'Production UOM', 'example' => ''],
            'description' => ['label' => 'Description', 'example' => ''],
            'technical_specification' => ['label' => 'Technical Specification', 'aliases' => ['specification'], 'example' => ''],
            'material' => ['label' => 'Material', 'example' => 'Stainless Steel'],
            'shape' => ['label' => 'Shape', 'example' => 'Round'],
            'size' => ['label' => 'Size', 'example' => ''],
            'length' => ['label' => 'Length', 'example' => ''],
            'width' => ['label' => 'Width', 'example' => ''],
            'height' => ['label' => 'Height', 'example' => '5'],
            'diameter' => ['label' => 'Diameter', 'example' => '25'],
            'thickness' => ['label' => 'Thickness', 'example' => ''],
            'dimension_uom' => ['label' => 'Dimension UOM', 'example' => 'MM'],
            'surface_finish' => ['label' => 'Surface Finish', 'aliases' => ['finish'], 'example' => 'Mirror'],
            'color' => ['label' => 'Color', 'aliases' => ['colour'], 'example' => ''],
            'weight_per_piece' => ['label' => 'Weight Per Piece', 'aliases' => ['weight'], 'example' => '12.5'],
            'weight_uom' => ['label' => 'Weight UOM', 'example' => 'GM'],
            'customer_part_number' => ['label' => 'Customer Part Number', 'example' => ''],
            'internal_part_number' => ['label' => 'Internal Part Number', 'example' => ''],
            'drawing_number' => ['label' => 'Drawing Number', 'aliases' => ['drawing no'], 'example' => 'DRG-1001'],
            'drawing_revision' => ['label' => 'Drawing Revision', 'aliases' => ['drawing rev'], 'example' => 'B'],
            'additional_specifications' => ['label' => 'Additional Specifications', 'example' => ''],
            'is_stock_item' => ['label' => 'Stock Item', 'example' => 'Yes'],
            'track_inventory' => ['label' => 'Track Inventory', 'example' => 'Yes'],
            'min_stock_level' => ['label' => 'Minimum Stock Level', 'example' => ''],
            'max_stock_level' => ['label' => 'Maximum Stock Level', 'example' => ''],
            'reorder_level' => ['label' => 'Reorder Level', 'example' => ''],
            'requires_qc' => ['label' => 'Requires QC', 'example' => 'Yes'],
            'is_purchased' => ['label' => 'Purchased', 'example' => 'No'],
            'is_manufactured' => ['label' => 'Manufactured', 'example' => 'Yes'],
            'is_sold' => ['label' => 'Sold', 'example' => 'Yes'],
            'is_active' => ['label' => 'Active', 'aliases' => ['status'], 'example' => 'Yes'],
        ];
    }

    public function findExisting(array $row): ?Model
    {
        $key = $this->keyOf($row);

        return $key ? Item::query()->where('company_id', $this->companyId())->where('code', $key)->first() : null;
    }

    public function validate(array $row, ?Model $existing, array $keysInFile): array
    {
        $errors = [];
        $data = ['code' => $this->blank($row['code'] ?? null) ? strtoupper(trim($row['code'])) : ($existing?->code)];
        $data['name'] = $this->blank($row['name'] ?? null);

        $categoryCode = strtoupper((string) $this->blank($row['category_code'] ?? null));
        $category = $categoryCode ? ItemCategory::query()->where('company_id', $this->companyId())->where('code', $categoryCode)->first() : null;
        if ($categoryCode && ! $category) {
            $errors[] = "Category {$categoryCode} does not exist.";
        }
        $data['category_id'] = $category?->id;

        $types = config('erp.master_data.item_types');
        $type = $this->blank($row['item_type'] ?? null);
        if ($type !== null && ! isset($types[$type])) {
            $type = array_search(strtolower($type), array_map('strtolower', $types), true) ?: $type;
        }
        $data['item_type'] = $type ?? $existing?->item_type ?? $category?->resolvedItemType();

        $gradeCode = strtoupper((string) $this->blank($row['material_grade'] ?? null));
        if ($gradeCode) {
            $data['material_grade_id'] = MaterialGrade::query()->where('company_id', $this->companyId())->where('code', $gradeCode)->value('id');
            if (! $data['material_grade_id']) {
                $errors[] = "Material grade {$gradeCode} does not exist.";
            }
        } else {
            $data['material_grade_id'] = null;
        }

        foreach (self::UOM_COLUMNS as $column => $field) {
            $code = strtoupper((string) $this->blank($row[$column] ?? null));
            $data[$field] = $code ? ($this->uomIds()[$code] ?? null) : null;
            if ($code && ! $data[$field]) {
                $errors[] = "Unit {$code} does not exist.";
            }
        }
        foreach (self::TEXT_COLUMNS as $column) {
            $data[$column] = $this->blank($row[$column] ?? null);
        }
        foreach (self::DECIMAL_COLUMNS as $column) {
            $data[$column] = $this->blank($row[$column] ?? null);
        }
        foreach (self::BOOLEAN_COLUMNS as $column) {
            $value = $this->bool($row[$column] ?? '', $existing ? (bool) $existing->{$column} : ($column === 'is_stock_item' || $column === 'track_inventory'));
            if ($value === null) {
                $errors[] = $this->columns()[$column]['label'].' must be Yes or No.';
            }
            $data[$column] = (bool) $value;
        }
        $active = $this->bool($row['is_active'] ?? '', true);
        if ($active === null) {
            $errors[] = 'Active must be Yes or No.';
        }
        $data['is_active'] = (bool) $active;

        $rules = ItemRequest::rulesFor($existing instanceof Item ? $existing : null, $this->companyId(), null);
        unset($rules['default_warehouse_id'], $rules['default_location_id'], $rules['attributes'], $rules['attributes.*']);
        array_push($errors, ...$this->runValidator($data, $rules, [
            'code.unique' => 'Item code already exists.',
            'category_id.exists' => 'The category is inactive.',
            'category_id.required' => 'Category Code is required.',
            'base_uom_id.required' => 'Base UOM is required.',
        ], fn ($validator, $d) => ItemRequest::crossFieldChecks($validator, $d)));

        if (! $existing && empty($data['code']) && config('erp.master_data.sku.mode') === 'manual') {
            $errors[] = 'Item Code / SKU is required.';
        }

        return ['errors' => array_values(array_unique($errors)), 'data' => $data];
    }

    public function persist(array $data, ?Model $existing): Model
    {
        $active = $data['is_active'];
        $payload = collect($data)->except('is_active')->all();
        if (! $existing) {
            $payload['is_active'] = $active;
        }

        $item = app(ItemService::class)->save($existing instanceof Item ? $existing : null, $payload, 'Excel import');
        if ($existing && $existing->is_active !== $active) {
            $item->forceFill(['is_active' => $active])->save();
        }

        $converter = app(UomConverter::class);
        foreach (['purchase_uom_id', 'production_uom_id'] as $field) {
            if ($item->{$field} && ! $converter->canConvert($item->{$field}, $item->base_uom_id, $item)) {
                throw ValidationException::withMessages([$field => 'The '.str_replace('_uom_id', '', $field).' unit cannot be converted to the base unit; add an item-specific conversion first.']);
            }
        }

        return $item;
    }

    /** @return array<string, int> */
    private function uomIds(): array
    {
        return $this->uomIds ??= UnitOfMeasure::query()->where('is_active', true)->pluck('id', 'code')->all();
    }
}
