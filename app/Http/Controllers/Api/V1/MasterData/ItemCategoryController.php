<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\ItemCategoryRequest;
use App\Http\Resources\MasterData\ItemCategoryResource;
use App\Models\MasterData\ItemCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemCategoryController extends MasterCrudController
{
    protected array $with = ['parent', 'creator'];

    protected array $withCount = ['items', 'children'];

    protected function modelClass(): string
    {
        return ItemCategory::class;
    }

    protected function resourceClass(): string
    {
        return ItemCategoryResource::class;
    }

    protected function requestClass(): string
    {
        return ItemCategoryRequest::class;
    }

    protected function entity(): string
    {
        return 'item-category';
    }

    protected function label(): string
    {
        return 'category';
    }

    protected function updatePermission(): string
    {
        return 'item-categories.update';
    }

    protected function deactivatePermission(): string
    {
        return 'item-categories.deactivate';
    }

    protected function filterRules(): array
    {
        return ['parent_id' => ['nullable', 'integer'], 'root' => ['nullable', 'boolean']];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['parent_id'] ?? null, fn ($q, $id) => $q->where('parent_id', $id))
            ->when($request->boolean('root'), fn ($q) => $q->whereNull('parent_id'));
    }

    protected function detail(Model $model): Model
    {
        return parent::detail($model)->load('itemAttributes');
    }

    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        $parentId = $data['parent_id'] ?? null;
        if ($model && $parentId) {
            // Walk up from the new parent; reaching this category is a cycle.
            $cursor = ItemCategory::find($parentId);
            for ($i = 0; $cursor && $i < 100; $i++) {
                if ($cursor->id === $model->id) {
                    throw ValidationException::withMessages(['parent_id' => 'A category cannot be placed under itself or one of its subcategories.']);
                }
                $cursor = $cursor->parent;
            }
        }

        return $data;
    }

    protected function afterSave(Model $model, array $data, Request $request, bool $created): void
    {
        if (array_key_exists('attributes', $data)) {
            $model->itemAttributes()->sync(collect($data['attributes'])->mapWithKeys(fn ($a) => [
                $a['attribute_id'] => ['is_required' => (bool) ($a['is_required'] ?? false)],
            ])->all());
        }
    }

    protected function statusGuard(Model $model, bool $activate): ?string
    {
        if ($activate && $model->parent && ! $model->parent->is_active) {
            return 'Activate the parent category first.';
        }

        return null;
    }

    /** Whole category tree (company), for pickers and the hierarchical list. */
    public function tree(Request $request): JsonResponse
    {
        $categories = $this->query($request)->withCount('items')->orderBy('name')->get();
        $children = $categories->groupBy('parent_id');

        $build = function (?int $parentId, int $depth) use (&$build, $children): array {
            if ($depth > 20) {
                return [];
            }

            return $children->get($parentId ?? '', collect())->map(fn (ItemCategory $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'sku_prefix' => $c->sku_prefix,
                'default_item_type' => $c->default_item_type, 'is_active' => $c->is_active, 'is_system' => $c->is_system,
                'items_count' => $c->items_count, 'children' => $build($c->id, $depth + 1),
            ])->values()->all();
        };

        return response()->json(['data' => $build(null, 0)]);
    }

    /**
     * Only unused, non-system leaf categories can be deleted; otherwise
     * deactivate them.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var ItemCategory $category */
        $category = $this->find($request, $id);

        $blockers = array_filter([
            $category->is_system ? 'it is a default category' : null,
            $category->items()->exists() ? 'items use it' : null,
            $category->children()->exists() ? 'it has subcategories' : null,
        ]);
        if ($blockers) {
            return response()->json(['message' => 'This category cannot be deleted because '.implode(' and ', $blockers).'. Deactivate it instead.'], 409);
        }

        $snapshot = $category->only(['id', 'code', 'name']);
        DB::transaction(fn () => $category->delete());
        $this->activity()->log('item-category.deleted', null, $snapshot, "Deleted category {$snapshot['code']}");

        return response()->json(['message' => 'Category deleted.']);
    }
}
