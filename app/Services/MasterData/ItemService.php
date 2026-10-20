<?php

namespace App\Services\MasterData;

use App\Models\MasterData\Item;
use App\Models\MasterData\ItemAttribute;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\ItemRevision;
use App\Models\MasterData\MaterialGrade;
use App\Services\ActivityLogger;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create/update logic for items, shared by the API and the Excel importer.
 * Specification changes bump the item revision and are kept in
 * item_revisions; structural fields lock once the item is referenced.
 */
class ItemService
{
    public const LOCKED_WHEN_REFERENCED = ['code', 'base_uom_id', 'item_type'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SkuGenerator $sku,
        private readonly ItemUsage $usage,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated item fields (+ optional "attributes" [attribute_id => value])
     */
    public function save(?Item $item, array $data, ?string $reason = null): Item
    {
        $attributes = $data['attributes'] ?? null;
        unset($data['attributes'], $data['revision_reason']);

        foreach (Item::DECIMAL_FIELDS as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $data[$field] = Decimal::normalize($data[$field]);
            } elseif (array_key_exists($field, $data)) {
                $data[$field] = null;
            }
        }

        $category = ItemCategory::query()->findOrFail($data['category_id'] ?? $item?->category_id);
        $data['item_type'] ??= $item?->item_type ?? $category->resolvedItemType();
        if (empty($data['item_type'])) {
            throw ValidationException::withMessages(['item_type' => 'Select an item type.']);
        }

        if ($item) {
            $this->guardLockedFields($item, $data);
        } else {
            $data['code'] = $this->resolveCode($data['code'] ?? null, $category, $data['material_grade_id'] ?? null);
        }

        $attributeErrors = $this->attributeErrors($category, $attributes, $item);
        if ($attributeErrors) {
            throw ValidationException::withMessages($attributeErrors);
        }

        return DB::transaction(function () use ($item, $data, $attributes, $reason) {
            $isNew = ! $item;
            $item ??= new Item;
            $before = $isNew ? [] : $item->only(array_keys($data));
            $beforeAttributes = $isNew ? [] : $item->attributeValues()->pluck('value', 'attribute_id')->all();

            $item->fill($data);
            if ($isNew) {
                $item->code = $data['code'];
                $item->revision = 1;
            }
            $item->save();

            if ($attributes !== null) {
                $this->syncAttributes($item, $attributes);
            }
            $afterAttributes = $item->attributeValues()->pluck('value', 'attribute_id')->all();

            if ($isNew) {
                ItemRevision::create(['item_id' => $item->id, 'revision' => 1, 'changes' => ['initial' => true], 'reason' => $reason ?? 'Created', 'created_by' => Auth::id()]);
                $this->activity->log('item.created', $item, ['code' => $item->code, 'name' => $item->name, 'category_id' => $item->category_id], "Created item {$item->code}");

                return $item;
            }

            $changes = $this->activity->diff($before, $item->only(array_keys($data)));
            $specChanges = array_intersect_key($changes, array_flip(Item::SPEC_FIELDS));
            if ($beforeAttributes != $afterAttributes) {
                $specChanges['attributes'] = ['from' => $beforeAttributes, 'to' => $afterAttributes];
                $changes['attributes'] = $specChanges['attributes'];
            }

            if ($specChanges) {
                $item->forceFill(['revision' => $item->revision + 1])->save();
                ItemRevision::create([
                    'item_id' => $item->id, 'revision' => $item->revision, 'changes' => $specChanges, 'reason' => $reason, 'created_by' => Auth::id(),
                ]);
            }
            if ($changes) {
                $this->activity->log('item.updated', $item, array_filter([
                    'changes' => $changes, 'revision' => $specChanges ? $item->revision : null, 'reason' => $reason,
                ]), "Updated item {$item->code}");
            }

            return $item;
        });
    }

    public function resolveCode(?string $code, ItemCategory $category, ?int $gradeId): string
    {
        $code = $code !== null ? strtoupper(trim($code)) : '';
        $mode = $this->sku->mode();

        if ($mode === 'auto' || ($mode === 'optional' && $code === '')) {
            return $this->sku->generate($category, $gradeId ? MaterialGrade::find($gradeId) : null);
        }
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Item code / SKU is required.']);
        }

        return $code;
    }

    private function guardLockedFields(Item $item, array $data): void
    {
        $changed = array_filter(self::LOCKED_WHEN_REFERENCED, fn ($f) => array_key_exists($f, $data) && (string) $data[$f] !== (string) $item->{$f});
        if (! $changed) {
            return;
        }

        if ($reasons = $this->usage->lockReasons($item)) {
            $errors = [];
            foreach ($changed as $field) {
                $errors[$field] = 'This cannot be changed because '.implode(' and ', $reasons).'. Create a new item instead.';
            }
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int|string, mixed>|null  $values  [attribute_id => value]
     * @return array<string, string>
     */
    private function attributeErrors(ItemCategory $category, ?array $values, ?Item $item): array
    {
        $applicable = ItemAttribute::query()
            ->join('item_category_attribute as ica', 'ica.attribute_id', '=', 'item_attributes.id')
            ->whereIn('ica.category_id', $category->lineageIds())
            ->where('item_attributes.is_active', true)
            ->get(['item_attributes.*', 'ica.is_required'])
            ->keyBy('id');

        $current = $item ? $item->attributeValues()->pluck('value', 'attribute_id')->all() : [];
        $values = $values ?? $current;
        $errors = [];

        foreach ($values as $attributeId => $value) {
            $attribute = $applicable->get((int) $attributeId) ?? ItemAttribute::find($attributeId);
            if (! $attribute) {
                $errors["attributes.{$attributeId}"] = 'Unknown attribute.';

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $message = match ($attribute->data_type) {
                'number' => Decimal::isNumeric((string) $value) ? null : 'Enter a number.',
                'boolean' => in_array((string) $value, ['0', '1', 'true', 'false'], true) ? null : 'Enter yes or no.',
                'select' => in_array((string) $value, (array) $attribute->options, true) ? null : 'Choose one of: '.implode(', ', (array) $attribute->options),
                default => mb_strlen((string) $value) > 500 ? 'Maximum 500 characters.' : null,
            };
            if ($message) {
                $errors["attributes.{$attributeId}"] = "{$attribute->name}: {$message}";
            }
        }

        foreach ($applicable->where('is_required', true) as $attribute) {
            $value = $values[$attribute->id] ?? $values[(string) $attribute->id] ?? null;
            if ($value === null || $value === '') {
                $errors["attributes.{$attribute->id}"] = "{$attribute->name} is required for this category.";
            }
        }

        return $errors;
    }

    private function syncAttributes(Item $item, array $values): void
    {
        foreach ($values as $attributeId => $value) {
            if ($value === null || $value === '') {
                $item->attributeValues()->where('attribute_id', $attributeId)->delete();

                continue;
            }
            $item->attributeValues()->updateOrCreate(['attribute_id' => (int) $attributeId], ['value' => (string) $value]);
        }
    }
}
