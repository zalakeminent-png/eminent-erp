<?php

namespace App\Services\MasterData\Import;

use App\Http\Requests\MasterData\MasterRequest;
use App\Models\MasterData\ItemCategory;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ItemCategoryImporter extends Importer
{
    public function entity(): string
    {
        return 'item-categories';
    }

    public function label(): string
    {
        return 'Item Categories';
    }

    public function createPermission(): string
    {
        return 'item-categories.create';
    }

    public function updatePermission(): string
    {
        return 'item-categories.update';
    }

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Category Code', 'required' => true, 'aliases' => ['code'], 'example' => 'RM-SS'],
            'name' => ['label' => 'Category Name', 'required' => true, 'aliases' => ['name', 'category'], 'example' => 'Stainless Steel Raw Material'],
            'parent_code' => ['label' => 'Parent Category Code', 'aliases' => ['parent', 'parent code'], 'example' => 'RM'],
            'description' => ['label' => 'Description', 'example' => ''],
            'sku_prefix' => ['label' => 'SKU Prefix', 'aliases' => ['prefix'], 'example' => ''],
            'default_item_type' => ['label' => 'Default Item Type', 'aliases' => ['item type', 'type'], 'example' => 'raw_material'],
            'is_active' => ['label' => 'Active', 'aliases' => ['status'], 'example' => 'Yes'],
        ];
    }

    public function findExisting(array $row): ?Model
    {
        $key = $this->keyOf($row);

        return $key ? ItemCategory::query()->where('company_id', $this->companyId())->where('code', $key)->first() : null;
    }

    public function validate(array $row, ?Model $existing, array $keysInFile): array
    {
        $types = config('erp.master_data.item_types');
        $type = $this->blank($row['default_item_type'] ?? null);
        if ($type !== null && ! isset($types[$type])) {
            $type = array_search(strtolower($type), array_map('strtolower', $types), true) ?: $type;
        }

        $data = [
            'code' => strtoupper((string) $this->blank($row['code'] ?? null)),
            'name' => $this->blank($row['name'] ?? null),
            'parent_code' => $this->blank($row['parent_code'] ?? null) ? strtoupper(trim($row['parent_code'])) : null,
            'description' => $this->blank($row['description'] ?? null),
            'sku_prefix' => $this->blank($row['sku_prefix'] ?? null) ? strtoupper(trim($row['sku_prefix'])) : null,
            'default_item_type' => $type,
            'is_active' => $this->bool($row['is_active'] ?? '', true),
        ];

        $errors = $this->runValidator($data, [
            'code' => ['required', 'max:30', 'regex:'.MasterRequest::CODE_REGEX],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku_prefix' => ['nullable', 'max:12', 'regex:/^[A-Z0-9]+$/'],
            'default_item_type' => ['nullable', Rule::in(array_keys($types))],
            'is_active' => ['required', 'boolean'],
        ], ['is_active.required' => 'Active must be Yes or No.']);

        if ($data['parent_code']) {
            $parent = ItemCategory::query()->where('company_id', $this->companyId())->where('code', $data['parent_code'])->first();
            if ($data['parent_code'] === $data['code']) {
                $errors[] = 'A category cannot be its own parent.';
            } elseif (! $parent && ! isset($keysInFile[$data['parent_code']])) {
                $errors[] = "Parent category {$data['parent_code']} does not exist (add it above this row or create it first).";
            } elseif ($parent && ! $parent->is_active) {
                $errors[] = "Parent category {$data['parent_code']} is inactive.";
            } elseif ($parent && $existing && in_array($existing->id, $parent->lineageIds(), true)) {
                $errors[] = 'This parent would create a circular category hierarchy.';
            }
        }

        return ['errors' => $errors, 'data' => $data];
    }

    public function persist(array $data, ?Model $existing): Model
    {
        $parentId = $data['parent_code']
            ? ItemCategory::query()->where('company_id', $this->companyId())->where('code', $data['parent_code'])->value('id')
            : null;

        $category = $existing ?? new ItemCategory;
        $before = $existing ? $existing->only(['name', 'parent_id', 'description', 'sku_prefix', 'default_item_type', 'is_active']) : [];
        $category->fill([
            'code' => $data['code'], 'name' => $data['name'], 'parent_id' => $parentId, 'description' => $data['description'],
            'sku_prefix' => $data['sku_prefix'], 'default_item_type' => $data['default_item_type'],
        ]);
        $category->is_active = $data['is_active'];
        $category->save();

        $logger = app(ActivityLogger::class);
        $existing
            ? $logger->log('item-category.updated', $category, ['changes' => $logger->diff($before, $category->only(array_keys($before))), 'source' => 'import'], "Updated category {$category->code} (import)")
            : $logger->log('item-category.created', $category, ['code' => $category->code, 'source' => 'import'], "Created category {$category->code} (import)");

        return $category;
    }
}
