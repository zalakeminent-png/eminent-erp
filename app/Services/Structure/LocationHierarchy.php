<?php

namespace App\Services\Structure;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integrity rules for the storage location tree: same-warehouse parents,
 * no cycles, configurable maximum depth, and reference checks before delete.
 */
class LocationHierarchy
{
    public function maxDepth(): int
    {
        return max(1, (int) config('erp.structure.max_location_depth', 6));
    }

    /**
     * Validates placing $location (null when creating) under $parentId in
     * $warehouse. Walks the full parent chain.
     */
    public function validatePlacement(Warehouse $warehouse, ?int $parentId, ?WarehouseLocation $location = null): void
    {
        if ($parentId === null) {
            if ($location && $this->subtreeHeight($location) > $this->maxDepth()) {
                $this->fail("Locations can be nested at most {$this->maxDepth()} levels deep.");
            }

            return;
        }

        $nodes = $this->nodes($warehouse);
        $parent = $nodes->get($parentId);

        if (! $parent) {
            // Exists in the table (validated earlier) but not in this warehouse.
            $this->fail('The parent location must belong to the same warehouse.');
        }

        if ($location && $parentId === $location->id) {
            $this->fail('A location cannot be its own parent.');
        }

        // Walk up from the parent; meeting the location itself means a cycle.
        $depth = 0;
        $seen = [];
        for ($cursor = $parent; $cursor; $cursor = $nodes->get($cursor->parent_id)) {
            if ($location && $cursor->id === $location->id) {
                $this->fail('This change would create a circular location hierarchy.');
            }
            if (isset($seen[$cursor->id])) {
                $this->fail('The existing location hierarchy is inconsistent; contact an administrator.');
            }
            $seen[$cursor->id] = true;
            $depth++;
        }

        $isNewParent = ! $location || $location->parent_id !== $parentId;
        if ($isNewParent && ! $parent->is_active) {
            $this->fail('Inactive locations cannot be selected as a parent.');
        }

        $height = $location ? $this->subtreeHeight($location, $nodes) : 1;
        if ($depth + $height > $this->maxDepth()) {
            $this->fail("Locations can be nested at most {$this->maxDepth()} levels deep.");
        }
    }

    /**
     * Number of levels from $location down to its deepest descendant (1 = leaf).
     */
    public function subtreeHeight(WarehouseLocation $location, ?Collection $nodes = null): int
    {
        $nodes ??= $this->nodes($location->warehouse);
        $children = $nodes->groupBy('parent_id');

        $height = function (int $id, int $guard = 0) use (&$height, $children): int {
            if ($guard > 1000) {
                return $guard;
            }

            return 1 + ($children->get($id, collect())->map(fn ($child) => $height($child->id, $guard + 1))->max() ?? 0);
        };

        return $height($location->id);
    }

    /**
     * Reasons a location may not be permanently deleted (empty = deletable).
     *
     * @return list<string>
     */
    public function deletionBlockers(WarehouseLocation $location): array
    {
        $blockers = [];

        if ($location->children()->exists()) {
            $blockers[] = 'it has child locations';
        }

        foreach (config('erp.structure.location_references', []) as $table => $column) {
            if (DB::table($table)->where($column, $location->id)->exists()) {
                $blockers[] = "it is referenced by {$table}";
            }
        }

        return $blockers;
    }

    /**
     * Nests a flat list of one warehouse's locations into a tree.
     *
     * @param  Collection<int, WarehouseLocation>  $locations
     * @return list<array<string, mixed>>
     */
    public function tree(Collection $locations): array
    {
        $sorted = $locations->sortBy('code', SORT_NATURAL)->values();
        $ids = $sorted->pluck('id')->flip();
        $children = $sorted->groupBy('parent_id');

        $node = function (WarehouseLocation $l, int $level) use (&$node, $children): array {
            return [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'type' => $l->type,
                'type_label' => config("erp.structure.location_types.{$l->type}", $l->type),
                'is_virtual' => $l->is_virtual,
                'is_pickable' => $l->is_pickable,
                'is_receivable' => $l->is_receivable,
                'is_active' => $l->is_active,
                'children' => $level > 100 ? [] : $children->get($l->id, collect())
                    ->map(fn ($child) => $node($child, $level + 1))->values()->all(),
            ];
        };

        // Roots, plus nodes whose parent was filtered out (e.g. by status).
        return $sorted
            ->filter(fn ($l) => $l->parent_id === null || ! $ids->has($l->parent_id))
            ->map(fn ($l) => $node($l, 1))
            ->values()->all();
    }

    /**
     * Ancestors from the root down to the direct parent.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function path(WarehouseLocation $location): array
    {
        $path = [];
        $cursor = $location->parent;
        while ($cursor && count($path) < 100) {
            array_unshift($path, ['id' => $cursor->id, 'code' => $cursor->code, 'name' => $cursor->name]);
            $cursor = $cursor->parent;
        }

        return $path;
    }

    /** @return Collection<int, WarehouseLocation> keyed by id */
    private function nodes(Warehouse $warehouse): Collection
    {
        return WarehouseLocation::query()
            ->where('warehouse_id', $warehouse->id)
            ->get(['id', 'parent_id', 'is_active'])
            ->keyBy('id');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['parent_id' => $message]);
    }
}
