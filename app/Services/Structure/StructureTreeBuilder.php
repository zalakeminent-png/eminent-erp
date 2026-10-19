<?php

namespace App\Services\Structure;

use App\Models\Company;
use App\Models\Department;
use App\Models\Factory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;

/**
 * Company → Factory → Departments / Warehouses → Locations, limited to the
 * user's data scope. Counts reflect real records only (no stock figures).
 */
class StructureTreeBuilder
{
    public function __construct(
        private readonly StructureScope $scope,
        private readonly LocationHierarchy $hierarchy,
    ) {}

    /**
     * @param  'active'|'inactive'|null  $status  active: hide inactive nodes; inactive: only branches containing inactive nodes
     * @return array<string, mixed>
     */
    public function build(User $user, ?string $search = null, ?string $status = null, ?int $factoryId = null): array
    {
        $onlyActive = $status === 'active';

        $factories = $this->scope->applyToFactories(Factory::query(), $user)
            ->when($factoryId, fn ($q) => $q->whereKey($factoryId))
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();
        $factoryIds = $factories->modelKeys();

        $departments = Department::query()->with(['type:id,name', 'manager:id,first_name,last_name', 'supervisor:id,first_name,last_name'])
            ->whereIn('factory_id', $factoryIds)
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get()->groupBy('factory_id');

        $warehouses = Warehouse::query()->with(['type:id,name', 'department:id,name'])
            ->whereIn('factory_id', $factoryIds)
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        $locations = WarehouseLocation::query()
            ->whereIn('warehouse_id', $warehouses->modelKeys())
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->get()->groupBy('warehouse_id');

        $warehouses = $warehouses->groupBy('factory_id');

        $match = $this->matcher($search, $status === 'inactive');

        $tree = $factories->map(function (Factory $factory) use ($departments, $warehouses, $locations, $match) {
            $factoryNode = ['code' => $factory->code, 'name' => $factory->name, 'is_active' => $factory->is_active];
            $factoryMatches = $match($factoryNode);

            $deptNodes = $departments->get($factory->id, collect())->map(fn (Department $d) => [
                'id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'is_active' => $d->is_active,
                'type' => $d->type?->name,
                'manager' => $d->manager?->full_name,
                'supervisor' => $d->supervisor?->full_name,
            ]);

            $whNodes = $warehouses->get($factory->id, collect())->map(function (Warehouse $w) use ($locations) {
                $locs = $locations->get($w->id, collect());

                return [
                    'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'is_active' => $w->is_active,
                    'type' => $w->type?->name,
                    'department' => $w->department?->name,
                    'locations_count' => $locs->count(),
                    'locations' => $this->hierarchy->tree($locs),
                ];
            });

            if (! $factoryMatches) {
                $deptNodes = $deptNodes->filter($match);
                $whNodes = $whNodes->map(function (array $w) use ($match) {
                    if ($match($w)) {
                        return $w;
                    }
                    $w['locations'] = $this->pruneLocations($w['locations'], $match);

                    return $w['locations'] ? $w : null;
                })->filter();

                if ($deptNodes->isEmpty() && $whNodes->isEmpty()) {
                    return null;
                }
            }

            return [
                'id' => $factory->id,
                'code' => $factory->code,
                'name' => $factory->name,
                'type' => $factory->type,
                'type_label' => config("erp.structure.factory_types.{$factory->type}", $factory->type),
                'city' => $factory->city,
                'is_active' => $factory->is_active,
                'counts' => [
                    'departments' => $deptNodes->count(),
                    'warehouses' => $whNodes->count(),
                    'locations' => $whNodes->sum(fn ($w) => $this->countNodes($w['locations'])),
                ],
                'departments' => $deptNodes->values()->all(),
                'warehouses' => $whNodes->values()->all(),
            ];
        })->filter()->values();

        $company = Company::current();

        return [
            'company' => $company ? ['id' => $company->id, 'name' => $company->name, 'code' => $company->code] : null,
            'totals' => [
                'factories' => $tree->count(),
                'departments' => $tree->sum('counts.departments'),
                'warehouses' => $tree->sum('counts.warehouses'),
                'locations' => $tree->sum('counts.locations'),
            ],
            'factories' => $tree->all(),
        ];
    }

    /**
     * Flattened rows for CSV export.
     *
     * @return list<array<int, string>>
     */
    public function rows(array $structure): array
    {
        $rows = [];
        $status = fn (array $n) => $n['is_active'] ? 'Active' : 'Inactive';

        foreach ($structure['factories'] as $f) {
            $rows[] = [$f['code'], $f['name'], 'Factory', $f['code'], $f['name'], '', $f['type_label'], $status($f)];
            foreach ($f['departments'] as $d) {
                $rows[] = [$f['code'], $f['name'], 'Department', $d['code'], $d['name'], '', (string) $d['type'], $status($d)];
            }
            foreach ($f['warehouses'] as $w) {
                $rows[] = [$f['code'], $f['name'], 'Warehouse', $w['code'], $w['name'], '', (string) $w['type'], $status($w)];
                $walk = function (array $nodes, string $path) use (&$walk, &$rows, $f, $status) {
                    foreach ($nodes as $n) {
                        $rows[] = [$f['code'], $f['name'], 'Location', $n['code'], $n['name'], $path, $n['type_label'], $status($n)];
                        $walk($n['children'], ltrim("{$path} / {$n['code']}", ' /'));
                    }
                };
                $walk($w['locations'], $w['code']);
            }
        }

        return $rows;
    }

    /** @return callable(array): bool */
    private function matcher(?string $search, bool $inactiveOnly): callable
    {
        $term = $search !== null && trim($search) !== '' ? mb_strtolower(trim($search)) : null;

        return function (array $node) use ($term, $inactiveOnly): bool {
            if ($inactiveOnly && $node['is_active']) {
                return false;
            }
            if ($term === null) {
                return true;
            }

            return str_contains(mb_strtolower($node['code']), $term) || str_contains(mb_strtolower($node['name']), $term);
        };
    }

    /** Keeps matching nodes (with their subtree) and the ancestors leading to them. */
    private function pruneLocations(array $nodes, callable $match): array
    {
        $kept = [];
        foreach ($nodes as $node) {
            if ($match($node)) {
                $kept[] = $node;

                continue;
            }
            $node['children'] = $this->pruneLocations($node['children'], $match);
            if ($node['children']) {
                $kept[] = $node;
            }
        }

        return $kept;
    }

    private function countNodes(array $nodes): int
    {
        return array_sum(array_map(fn ($n) => 1 + $this->countNodes($n['children']), $nodes));
    }
}
