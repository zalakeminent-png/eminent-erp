<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Services\Inventory\InventoryReports;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    private const FILTERS = [
        'search', 'factory_id', 'warehouse_id', 'location_id', 'item_id', 'category_id', 'item_type', 'status', 'transaction_type',
        'department_id', 'supplier_id', 'date_from', 'date_to', 'variance_only', 'user_id',
    ];

    public function __construct(private readonly InventoryReports $reports) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(InventoryReports::REPORTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()]);
    }

    public function show(Request $request, string $report): JsonResponse|BinaryFileResponse
    {
        abort_unless(isset(InventoryReports::REPORTS[$report]), 404);
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $built = $this->reports->build($report, $request->only(self::FILTERS), $request->user());

        if ($format = $request->query('export')) {
            abort_unless($request->user()->can('inventory.reports.export'), 403);
            $keys = array_column($built['columns'], 'key');

            return Spreadsheet::download($report, array_column($built['columns'], 'label'),
                $built['query']->cursor()->map(fn ($row) => array_map(fn ($k) => ($built['map'])($row)[$k] ?? null, $keys)),
                $format === 'csv' ? 'csv' : 'xlsx');
        }

        $page = $built['query']->paginate(min(200, $request->integer('per_page', 50) ?: 50))->withQueryString();

        return response()->json([
            'report' => ['key' => $report, 'label' => InventoryReports::REPORTS[$report]],
            'columns' => $built['columns'],
            'data' => collect($page->items())->map($built['map'])->values(),
            'summary' => $built['summary'] ?? null,
            'meta' => InventoryRefs::meta($page),
        ]);
    }
}
