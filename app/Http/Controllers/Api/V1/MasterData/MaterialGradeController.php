<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\MaterialGradeRequest;
use App\Http\Resources\MasterData\MaterialGradeResource;
use App\Models\MasterData\MaterialGrade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class MaterialGradeController extends MasterCrudController
{
    protected array $searchable = ['code', 'name', 'standard'];

    protected array $with = ['family', 'creator'];

    protected array $withCount = ['items'];

    protected function modelClass(): string
    {
        return MaterialGrade::class;
    }

    protected function resourceClass(): string
    {
        return MaterialGradeResource::class;
    }

    protected function requestClass(): string
    {
        return MaterialGradeRequest::class;
    }

    protected function entity(): string
    {
        return 'material-grade';
    }

    protected function label(): string
    {
        return 'material grade';
    }

    protected function updatePermission(): string
    {
        return 'material-grades.update';
    }

    protected function deactivatePermission(): string
    {
        return 'material-grades.update';
    }

    protected function filterRules(): array
    {
        return ['family_id' => ['nullable', 'integer']];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['family_id'] ?? null, fn ($q, $id) => $q->where('family_id', $id));
    }

    protected function statusGuard(Model $model, bool $activate): ?string
    {
        return $activate && ! $model->family->is_active ? 'Activate the material family first.' : null;
    }
}
