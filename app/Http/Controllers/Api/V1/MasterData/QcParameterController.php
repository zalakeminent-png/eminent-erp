<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\QcParameterRequest;
use App\Http\Resources\MasterData\QcParameterResource;
use App\Models\MasterData\QcParameter;
use App\Models\MasterData\QcSpecificationLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** QC parameter catalogue (managed with qc-specifications permissions). */
class QcParameterController extends MasterCrudController
{
    protected array $sortable = ['code', 'name', 'data_type', 'is_active', 'created_at'];

    protected array $with = ['defaultUom'];

    protected function modelClass(): string
    {
        return QcParameter::class;
    }

    protected function resourceClass(): string
    {
        return QcParameterResource::class;
    }

    protected function requestClass(): string
    {
        return QcParameterRequest::class;
    }

    protected function entity(): string
    {
        return 'qc-parameter';
    }

    protected function label(): string
    {
        return 'QC parameter';
    }

    protected function updatePermission(): string
    {
        return 'qc-specifications.update';
    }

    protected function deactivatePermission(): string
    {
        return 'qc-specifications.update';
    }

    protected function filterRules(): array
    {
        return ['data_type' => ['nullable', Rule::in(array_keys(config('erp.master_data.qc_data_types')))]];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['data_type'] ?? null, fn ($q, $t) => $q->where('data_type', $t));
    }

    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        if ($model && QcSpecificationLine::query()->where('parameter_id', $model->id)->exists()) {
            if ($data['data_type'] !== $model->data_type) {
                throw ValidationException::withMessages(['data_type' => 'Specifications already use this parameter, so its data type cannot change.']);
            }
            $removed = array_diff((array) $model->options, (array) ($data['options'] ?? []));
            if ($removed) {
                throw ValidationException::withMessages(['options' => 'Options already used by specifications cannot be removed: '.implode(', ', $removed)]);
            }
        }
        $data['options'] = $data['data_type'] === QcParameter::SELECTION ? array_values($data['options'] ?? []) : null;

        return $data;
    }
}
