<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\ProcessRequest;
use App\Http\Resources\MasterData\ProcessResource;
use App\Models\MasterData\ManufacturingProcess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcessController extends MasterCrudController
{
    protected array $sortable = ['code', 'name', 'process_type', 'is_active', 'created_at'];

    protected array $with = ['defaultDepartment', 'creator'];

    protected array $withCount = ['machines'];

    protected function modelClass(): string
    {
        return ManufacturingProcess::class;
    }

    protected function resourceClass(): string
    {
        return ProcessResource::class;
    }

    protected function requestClass(): string
    {
        return ProcessRequest::class;
    }

    protected function entity(): string
    {
        return 'process';
    }

    protected function label(): string
    {
        return 'process';
    }

    protected function updatePermission(): string
    {
        return 'processes.update';
    }

    protected function deactivatePermission(): string
    {
        return 'processes.update';
    }

    protected function filterRules(): array
    {
        return [
            'process_type' => ['nullable', Rule::in(ManufacturingProcess::TYPES)],
            'department_id' => ['nullable', 'integer'],
            'requires_qc' => ['nullable', 'boolean'],
        ];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['process_type'] ?? null, fn ($q, $t) => $q->where('process_type', $t))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('default_department_id', $id))
            ->when(isset($filters['requires_qc']), fn ($q) => $q->where('requires_qc', $request->boolean('requires_qc')));
    }
}
