<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Inventory\ReasonCode;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Configurable reason codes for scrap, rejection, adjustments, returns and QC. */
class ReasonCodeController extends Controller
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): JsonResponse
    {
        $codes = ReasonCode::query()
            ->when($request->filled('category'), fn ($q) => $q->whereIn('category', explode(',', (string) $request->query('category'))))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('category')->orderBy('code')->get();

        return response()->json(['data' => $codes->map(fn (ReasonCode $c) => $c->only(['id', 'code', 'name', 'category', 'is_active']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $code = ReasonCode::create($data);
        $this->logger->log('inventory.reason-code.created', $code, $data, "Reason code {$code->code} created");

        return response()->json(['data' => $code->only(['id', 'code', 'name', 'category', 'is_active']), 'message' => 'Reason code created.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $code = ReasonCode::query()->findOrFail($id);
        $data = $this->validated($request, $code);
        $before = $code->only(array_keys($data));
        $code->update($data);
        $this->logger->log('inventory.reason-code.updated', $code, ['changes' => $this->logger->diff($before, $data)], "Reason code {$code->code} updated");

        return response()->json(['data' => $code->only(['id', 'code', 'name', 'category', 'is_active']), 'message' => 'Reason code saved.']);
    }

    private function validated(Request $request, ?ReasonCode $code = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('inventory_reason_codes', 'code')->where('company_id', Company::current()?->id)->ignore($code?->id)],
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(config('erp.inventory.reason_categories'))],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);

        return $data;
    }
}
