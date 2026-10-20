<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\UomConversionRequest;
use App\Http\Resources\MasterData\UomConversionResource;
use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\MasterData\UomConversion;
use App\Services\MasterData\UomConverter;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UomConversionController extends MasterCrudController
{
    protected bool $companyScoped = false;

    protected array $searchable = [];

    protected array $sortable = ['created_at', 'is_active'];

    protected string $defaultSort = 'created_at';

    protected array $with = ['fromUom', 'toUom'];

    protected function modelClass(): string
    {
        return UomConversion::class;
    }

    protected function resourceClass(): string
    {
        return UomConversionResource::class;
    }

    protected function requestClass(): string
    {
        return UomConversionRequest::class;
    }

    protected function entity(): string
    {
        return 'uom-conversion';
    }

    protected function label(): string
    {
        return 'conversion';
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
        return ['uom_id' => ['nullable', 'integer'], 'dimension' => ['nullable', Rule::in(array_keys(config('erp.master_data.uom_dimensions')))]];
    }

    protected function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $query->when($filters['uom_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('from_uom_id', $id)->orWhere('to_uom_id', $id)))
            ->when($filters['dimension'] ?? null, fn ($q, $d) => $q->whereHas('fromUom', fn ($u) => $u->where('dimension', $d)));
    }

    protected function prepare(array $data, ?Model $model, Request $request): array
    {
        $from = UnitOfMeasure::query()->findOrFail($data['from_uom_id'] ?? $model->from_uom_id);
        $to = UnitOfMeasure::query()->findOrFail($data['to_uom_id'] ?? $model->to_uom_id);

        if ($errors = app(UomConverter::class)->validateUniversal($from, $to, $data['factor'], $model?->id)) {
            throw ValidationException::withMessages(['factor' => $errors]);
        }
        $data['factor'] = Decimal::normalize($data['factor']);

        return $data;
    }

    /**
     * Converts a quantity, e.g. GET /uom-conversions/convert?quantity=2.5&from=KG&to=GM
     * (optionally &item_id= for item-specific conversions).
     */
    public function convert(Request $request, UomConverter $converter): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'regex:/^\d{1,18}(\.\d{1,12})?$/'],
            'from' => ['required', 'string', Rule::exists('units_of_measure', 'code')],
            'to' => ['required', 'string', Rule::exists('units_of_measure', 'code')],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
        ]);

        $item = isset($data['item_id']) ? Item::find($data['item_id']) : null;
        $from = UnitOfMeasure::where('code', $data['from'])->firstOrFail();
        $to = UnitOfMeasure::where('code', $data['to'])->firstOrFail();

        return response()->json(['data' => [
            'quantity' => Decimal::normalize($data['quantity']),
            'from' => $from->code,
            'to' => $to->code,
            ...$converter->convert($data['quantity'], $from, $to, $item),
        ]]);
    }
}
