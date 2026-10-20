<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\UomRequest;
use App\Http\Resources\MasterData\UomResource;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemUomConversion;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\MasterData\UomConversion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UomController extends MasterCrudController
{
    protected bool $companyScoped = false;

    protected array $sortable = ['code', 'name', 'dimension', 'decimal_precision', 'is_active', 'created_at'];

    protected function modelClass(): string
    {
        return UnitOfMeasure::class;
    }

    protected function resourceClass(): string
    {
        return UomResource::class;
    }

    protected function requestClass(): string
    {
        return UomRequest::class;
    }

    protected function entity(): string
    {
        return 'uom';
    }

    protected function label(): string
    {
        return 'unit';
    }

    protected function updatePermission(): string
    {
        return 'uoms.update';
    }

    protected function deactivatePermission(): string
    {
        return 'uoms.update';
    }

    protected function filterRules(): array
    {
        return ['dimension' => ['nullable', Rule::in(array_keys(config('erp.master_data.uom_dimensions')))]];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['dimension'] ?? null, fn ($q, $d) => $q->where('dimension', $d));
    }

    /**
     * Dimension and precision are structural: once a unit is used by
     * conversions or items, changing them could silently alter quantities.
     */
    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        if ($model && $this->inUse($model)) {
            $errors = [];
            if ($data['dimension'] !== $model->dimension) {
                $errors['dimension'] = 'This unit is already used by conversions or items, so its dimension cannot change.';
            }
            if ((int) $data['decimal_precision'] < $model->decimal_precision) {
                $errors['decimal_precision'] = 'Precision can only be increased for a unit that is in use.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $data;
    }

    protected function statusGuard(Model $model, bool $activate): ?string
    {
        if (! $activate && Item::query()->where('base_uom_id', $model->id)->where('is_active', true)->exists()) {
            return 'Active items use this unit as their base unit.';
        }

        return null;
    }

    private function inUse(Model $uom): bool
    {
        return UomConversion::query()->where('from_uom_id', $uom->id)->orWhere('to_uom_id', $uom->id)->exists()
            || ItemUomConversion::query()->where('uom_id', $uom->id)->exists()
            || Item::query()->where('base_uom_id', $uom->id)->exists();
    }
}
