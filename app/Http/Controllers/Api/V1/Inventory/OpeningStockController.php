<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\OpeningStockImport;
use App\Models\Inventory\OpeningStockRow;
use App\Services\Inventory\OpeningStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Opening stock wizard. Imports are company-wide documents; every row is
 * validated against the uploader's factory access.
 */
class OpeningStockController extends Controller
{
    public function __construct(private readonly OpeningStockService $service) {}

    public function template(): BinaryFileResponse
    {
        return $this->service->template();
    }

    public function index(Request $request): JsonResponse
    {
        $query = OpeningStockImport::query()->with(['creator', 'approver', 'poster']);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $page = $query->orderByDesc('id')->paginate(min(100, $request->integer('per_page', 15) ?: 15))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (OpeningStockImport $i) => $this->header($i, $request))->values(),
            'meta' => InventoryRefs::meta($page),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $import = OpeningStockImport::query()->with(['creator', 'approver', 'poster'])->findOrFail($id);
        $rows = $import->rows()->with(['item', 'location.warehouse', 'uom'])
            ->when(in_array($request->query('row_status'), ['accepted', 'rejected'], true), fn ($q) => $q->where('status', $request->query('row_status')))
            ->paginate(min(200, $request->integer('per_page', 50) ?: 50))->withQueryString();

        return response()->json([
            'data' => $this->header($import, $request) + [
                'columns' => collect(OpeningStockService::COLUMNS)->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'required' => $c[1]])->values(),
                'rows' => collect($rows->items())->map(fn (OpeningStockRow $r) => [
                    'id' => $r->id,
                    'row_number' => $r->row_number,
                    'status' => $r->status,
                    'errors' => $r->errors ?? [],
                    'raw' => $r->raw,
                    'item' => Refs::coded($r->item),
                    'location' => InventoryRefs::location($r->location),
                    'stock_status' => $r->stock_status,
                    'quantity' => Refs::decimal($r->quantity),
                    'uom' => Refs::uom($r->uom),
                    'base_quantity' => Refs::decimal($r->base_quantity),
                    'unit_cost' => Refs::decimal($r->unit_cost),
                ])->values(),
                'rows_meta' => InventoryRefs::meta($rows),
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:xlsx,csv']], ['file.extensions' => 'Upload an .xlsx or .csv file.']);
        $import = $this->service->upload($request->file('file'), $request->user());

        return response()->json(['data' => $this->header($import, $request), 'message' => 'File uploaded. Check the column mapping, then validate.'], 201);
    }

    public function action(Request $request, int $id, string $action): JsonResponse
    {
        $import = OpeningStockImport::query()->findOrFail($id);
        $user = $request->user();
        $permission = match ($action) {
            'validate' => 'inventory.opening-stock.import',
            'submit' => 'inventory.opening-stock.create|inventory.opening-stock.import',
            'approve', 'reject' => 'inventory.opening-stock.approve',
            'post', 'reverse' => 'inventory.opening-stock.post',
        };
        abort_unless($user->hasAnyPermission(explode('|', $permission)), 403);

        $import = match ($action) {
            'validate' => $this->service->validate($import, (array) $request->validate(['mapping' => ['required', 'array']])['mapping'], $user),
            'submit' => $this->service->submit($import, $user),
            'approve' => $this->service->approve($import, $user),
            'reject' => $this->service->reject($import, $user),
            'post' => $this->service->post($import, $user),
            'reverse' => $this->service->reverse($import, $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'], $user),
        };

        return response()->json(['data' => $this->header($import->load(['creator', 'approver', 'poster']), $request), 'message' => 'Done.']);
    }

    public function errors(int $id): BinaryFileResponse
    {
        return $this->service->errorReport(OpeningStockImport::query()->findOrFail($id));
    }

    private function header(OpeningStockImport $i, Request $request): array
    {
        $user = $request->user();
        $can = fn (string $p) => $user->hasAnyPermission(explode('|', $p));
        $actions = array_keys(array_filter([
            'validate' => in_array($i->status, [OpeningStockImport::UPLOADED, OpeningStockImport::DRAFT], true) && $can('inventory.opening-stock.import'),
            'submit' => $i->status === OpeningStockImport::DRAFT && $i->accepted_rows > 0 && $can('inventory.opening-stock.create|inventory.opening-stock.import'),
            'approve' => $i->status === OpeningStockImport::SUBMITTED && $can('inventory.opening-stock.approve'),
            'reject' => in_array($i->status, [OpeningStockImport::SUBMITTED, OpeningStockImport::APPROVED], true) && $can('inventory.opening-stock.approve'),
            'post' => in_array($i->status, [OpeningStockImport::APPROVED, OpeningStockImport::FAILED], true) && $can('inventory.opening-stock.post'),
            'reverse' => $i->status === OpeningStockImport::POSTED && $can('inventory.opening-stock.post'),
        ]));

        return [
            'id' => $i->id,
            'import_number' => $i->import_number,
            'original_name' => $i->original_name,
            'status' => $i->status,
            'headers' => $i->headers,
            'mapping' => $i->mapping,
            'total_rows' => $i->total_rows,
            'accepted_rows' => $i->accepted_rows,
            'rejected_rows' => $i->rejected_rows,
            'transaction_id' => $i->transaction_id,
            'reversal_transaction_id' => $i->reversal_transaction_id,
            'failure_message' => $i->failure_message,
            'reversal_reason' => $i->reversal_reason,
            'created_by' => $i->relationLoaded('creator') ? Refs::user($i->creator) : null,
            'approved_by' => $i->relationLoaded('approver') ? Refs::user($i->approver) : null,
            'posted_by' => $i->relationLoaded('poster') ? Refs::user($i->poster) : null,
            'submitted_at' => $i->submitted_at?->toIso8601String(),
            'approved_at' => $i->approved_at?->toIso8601String(),
            'posted_at' => $i->posted_at?->toIso8601String(),
            'reversed_at' => $i->reversed_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
            'allowed_actions' => $actions,
        ];
    }
}
