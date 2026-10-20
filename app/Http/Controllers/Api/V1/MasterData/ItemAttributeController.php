<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\ItemAttributeRequest;
use App\Http\Resources\MasterData\ItemAttributeResource;
use App\Models\MasterData\ItemAttribute;
use App\Models\MasterData\ItemAttributeValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Configurable item attribute definitions (managed with item-categories permissions). */
class ItemAttributeController extends MasterCrudController
{
    protected array $sortable = ['code', 'name', 'sort_order', 'is_active', 'created_at'];

    protected string $defaultSort = 'sort_order';

    protected array $with = ['uom', 'categories'];

    protected function modelClass(): string
    {
        return ItemAttribute::class;
    }

    protected function resourceClass(): string
    {
        return ItemAttributeResource::class;
    }

    protected function requestClass(): string
    {
        return ItemAttributeRequest::class;
    }

    protected function entity(): string
    {
        return 'item-attribute';
    }

    protected function label(): string
    {
        return 'attribute';
    }

    protected function updatePermission(): string
    {
        return 'item-categories.update';
    }

    protected function deactivatePermission(): string
    {
        return 'item-categories.update';
    }

    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        if ($model && $data['data_type'] !== $model->data_type && ItemAttributeValue::query()->where('attribute_id', $model->id)->exists()) {
            throw ValidationException::withMessages(['data_type' => 'Items already store values for this attribute, so its data type cannot change.']);
        }
        $data['options'] = $data['data_type'] === 'select' ? array_values($data['options'] ?? []) : null;

        return $data;
    }
}
