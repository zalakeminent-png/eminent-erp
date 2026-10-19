<?php

namespace App\Services\Structure;

use App\Models\Department;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Row-level data scope for the factory structure.
 *
 * Permissions decide WHAT a user may do; this decides WHERE. A user holding
 * `factories.access-all` sees every factory. Everyone else sees only the
 * factories they are assigned to: their primary factory plus the factories
 * of their primary and additional departments. Role names are never used.
 */
class StructureScope
{
    public const GLOBAL_PERMISSION = 'factories.access-all';

    /** @var array<int, list<int>|null> */
    private array $cache = [];

    public function hasGlobalAccess(User $user): bool
    {
        return $user->can(self::GLOBAL_PERMISSION);
    }

    /**
     * @return list<int>|null null when unrestricted
     */
    public function factoryIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->cache)) {
            return $this->cache[$user->id];
        }

        if ($this->hasGlobalAccess($user)) {
            return $this->cache[$user->id] = null;
        }

        $departmentIds = $user->additionalDepartments()->pluck('departments.id')->push($user->department_id)->filter();

        $ids = Department::query()->whereKey($departmentIds)->pluck('factory_id')
            ->push($user->factory_id)
            ->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();

        return $this->cache[$user->id] = $ids;
    }

    /** Call after assignments change within the same request. */
    public function forget(): void
    {
        $this->cache = [];
    }

    public function canAccessFactory(User $user, ?int $factoryId): bool
    {
        $ids = $this->factoryIds($user);

        return $factoryId !== null && ($ids === null || in_array($factoryId, $ids, true));
    }

    /**
     * Responds 404 (not 403) so out-of-scope records are not disclosed.
     */
    public function ensureFactory(User $user, ?int $factoryId): void
    {
        if (! $this->canAccessFactory($user, $factoryId)) {
            throw (new ModelNotFoundException)->setModel(Factory::class);
        }
    }

    public function applyToFactories(Builder $query, User $user): Builder
    {
        $ids = $this->factoryIds($user);

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn('id'), $ids);
    }

    /** For tables with a factory_id column (departments, warehouses). */
    public function applyByFactory(Builder $query, User $user, string $column = 'factory_id'): Builder
    {
        $ids = $this->factoryIds($user);

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn($column), $ids);
    }

    public function applyToLocations(Builder $query, User $user): Builder
    {
        $ids = $this->factoryIds($user);

        return $ids === null ? $query : $query->whereHas('warehouse', fn ($w) => $w->whereIn('factory_id', $ids));
    }
}
