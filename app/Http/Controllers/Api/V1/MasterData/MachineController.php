<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\MachineRequest;
use App\Http\Resources\MasterData\MachineResource;
use App\Models\Department;
use App\Models\MasterData\Machine;
use App\Models\MasterData\RoutingOperation;
use App\Services\Structure\StructureScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Machines & workstations, limited to the user's factory data scope
 * (Module 2). No maintenance scheduling or live monitoring here.
 */
class MachineController extends MasterCrudController
{
    protected bool $companyScoped = false;

    protected array $searchable = ['code', 'name', 'machine_type', 'workstation'];

    protected array $sortable = ['code', 'name', 'machine_type', 'is_active', 'created_at'];

    protected array $with = ['factory', 'department', 'capacityUom', 'processes', 'creator'];

    protected function modelClass(): string
    {
        return Machine::class;
    }

    protected function resourceClass(): string
    {
        return MachineResource::class;
    }

    protected function requestClass(): string
    {
        return MachineRequest::class;
    }

    protected function entity(): string
    {
        return 'machine';
    }

    protected function label(): string
    {
        return 'machine';
    }

    protected function updatePermission(): string
    {
        return 'machines.update';
    }

    protected function deactivatePermission(): string
    {
        return 'machines.update';
    }

    protected function query(Request $request): Builder
    {
        return app(StructureScope::class)->applyByFactory(Machine::query(), $request->user());
    }

    protected function filterRules(): array
    {
        return ['factory_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'process_id' => ['nullable', 'integer']];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['factory_id'] ?? null, fn ($q, $id) => $q->where('factory_id', $id))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['process_id'] ?? null, fn ($q, $id) => $q->whereHas('processes', fn ($p) => $p->whereKey($id)));
    }

    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        $department = Department::query()->findOrFail($data['department_id']);
        if ($model && $department->factory_id !== $model->factory_id) {
            throw ValidationException::withMessages(['department_id' => 'A machine cannot move to a department of another factory.']);
        }
        $data['factory_id'] = $department->factory_id;

        if ($model && array_key_exists('process_ids', $data)) {
            // Processes used by this machine in approved routings must stay linked.
            $locked = RoutingOperation::query()->where('machine_id', $model->id)
                ->whereHas('routing', fn ($q) => $q->whereNotNull('approved_at'))
                ->pluck('process_id')->unique()->diff($data['process_ids']);
            if ($locked->isNotEmpty()) {
                throw ValidationException::withMessages(['process_ids' => 'Processes used with this machine in approved routings cannot be removed.']);
            }
        }

        return $data;
    }

    protected function afterSave(Model $model, array $data, Request $request, bool $created): void
    {
        if (array_key_exists('process_ids', $data)) {
            $model->processes()->sync($data['process_ids']);
        }
    }
}
