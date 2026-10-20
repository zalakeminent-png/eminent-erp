<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\MaterialFamilyRequest;
use App\Http\Resources\MasterData\MaterialFamilyResource;
use App\Models\MasterData\MaterialFamily;
use Illuminate\Database\Eloquent\Model;

class MaterialFamilyController extends MasterCrudController
{
    protected array $withCount = ['grades'];

    protected function modelClass(): string
    {
        return MaterialFamily::class;
    }

    protected function resourceClass(): string
    {
        return MaterialFamilyResource::class;
    }

    protected function requestClass(): string
    {
        return MaterialFamilyRequest::class;
    }

    protected function entity(): string
    {
        return 'material-family';
    }

    protected function label(): string
    {
        return 'material family';
    }

    protected function updatePermission(): string
    {
        return 'material-grades.update';
    }

    protected function deactivatePermission(): string
    {
        return 'material-grades.update';
    }

    protected function statusGuard(Model $model, bool $activate): ?string
    {
        return ! $activate && $model->grades()->where('is_active', true)->exists()
            ? 'Deactivate the grades of this family first.'
            : null;
    }
}
