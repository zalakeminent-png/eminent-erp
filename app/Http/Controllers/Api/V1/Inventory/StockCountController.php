<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\StockCountLine;
use App\Models\Inventory\StockCountSession;
use App\Services\Inventory\StockCountService;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockCountController extends Controller
{
    public function __construct(
        private readonly StockCountService $counts,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockCountSession::query()->with(['warehouse', 'creator', 'approver'])->withCount('lines');
        $this->scope->applyByFactory($query, $request->user());
        foreach (['status', 'warehouse_id', 'factory_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($term = trim((string) $request->query('search'))) {
            $query->whereRaw('LOWER(count_number) LIKE ?', [Search::like($term)]);
        }
        $page = $query->orderByDesc('id')->paginate(min(100, $request->integer('per_page', 15) ?: 15))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (StockCountSession $s) => $this->header($s, $request))->values(),
            'meta' => InventoryRefs::meta($page),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse|BinaryFileResponse
    {
        $session = $this->find($request, $id)->load(['warehouse', 'creator', 'approver', 'lines.item', 'lines.location.warehouse', 'lines.baseUom', 'lines.counter']);

        if ($format = $request->query('export')) {
            abort_unless($request->user()->can('inventory.reports.export'), 403);

            return Spreadsheet::download("{$session->count_number}-reconciliation", [
                'Item Code', 'Item Name', 'Location', 'Status', 'UOM', 'System Qty', 'Counted Qty', 'Variance', 'Counted By', 'Remarks',
            ], $session->lines->map(fn (StockCountLine $l) => [
                $l->item?->code, $l->item?->name, $l->location?->code, $l->stock_status, $l->baseUom?->code,
                Refs::decimal($l->system_quantity), Refs::decimal($l->counted_quantity), $l->variance(), $l->counter?->full_name, $l->remarks,
            ]), $format === 'csv' ? 'csv' : 'xlsx');
        }

        return response()->json(['data' => $this->header($session, $request) + [
            'lines' => $session->lines->sortBy(fn ($l) => [$l->location?->code, $l->item?->code])->values()->map(fn (StockCountLine $l) => [
                'id' => $l->id,
                'item' => Refs::coded($l->item),
                'location' => InventoryRefs::location($l->location),
                'stock_status' => $l->stock_status,
                'uom' => Refs::uom($l->baseUom),
                'system_quantity' => Refs::decimal($l->system_quantity),
                'counted_quantity' => Refs::decimal($l->counted_quantity),
                'variance' => $l->variance(),
                'remarks' => $l->remarks,
                'counted_by' => Refs::user($l->counter),
                'counted_at' => $l->counted_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:item_categories,id'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer', 'exists:items,id'],
            'lock_locations' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $session = $this->counts->create($data, $request->user());

        return response()->json(['data' => $this->header($session->load(['warehouse', 'creator']), $request), 'message' => "Stock count {$session->count_number} created."], 201);
    }

    public function action(Request $request, int $id, string $action): JsonResponse
    {
        $session = $this->find($request, $id);
        $user = $request->user();
        $permission = match ($action) {
            'start', 'cancel' => 'inventory.counts.create',
            'submit' => 'inventory.counts.submit',
            'approve' => 'inventory.counts.approve',
            'post' => 'inventory.counts.post',
        };
        abort_unless($user->can($permission), 403);

        $session = match ($action) {
            'start' => $this->counts->start($session, $user),
            'submit' => $this->counts->submit($session, $user),
            'approve' => $this->counts->approve($session, $user),
            'post' => $this->counts->post($session, $user),
            'cancel' => $this->counts->cancel($session, $user),
        };

        return response()->json(['data' => $this->header($session->load(['warehouse', 'creator', 'approver']), $request), 'message' => 'Done.']);
    }

    /** Records counted quantities (mobile counting screen). */
    public function record(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.line_id' => ['nullable', 'integer'],
            'entries.*.item_id' => ['required_without:entries.*.line_id', 'nullable', 'integer'],
            'entries.*.location_id' => ['required_without:entries.*.line_id', 'nullable', 'integer'],
            'entries.*.stock_status' => ['nullable', 'string'],
            'entries.*.counted_quantity' => ['required', 'string', 'max:30'],
            'entries.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $session = $this->counts->record($this->find($request, $id), $data['entries'], $request->user());

        return response()->json(['data' => $this->header($session->load(['warehouse', 'creator']), $request), 'message' => 'Counts saved.']);
    }

    private function find(Request $request, int $id): StockCountSession
    {
        $session = StockCountSession::query()->findOrFail($id);
        $this->scope->ensureFactory($request->user(), $session->factory_id);

        return $session;
    }

    private function header(StockCountSession $s, Request $request): array
    {
        $user = $request->user();
        $actions = collect([
            'start' => [StockCountSession::DRAFT, 'inventory.counts.create'],
            'record' => [StockCountSession::COUNTING, 'inventory.counts.create|inventory.counts.submit'],
            'submit' => [StockCountSession::COUNTING, 'inventory.counts.submit'],
            'approve' => [StockCountSession::SUBMITTED, 'inventory.counts.approve'],
            'post' => [StockCountSession::APPROVED, 'inventory.counts.post'],
        ])->filter(fn ($rule, $action) => $s->status === $rule[0] && $user->hasAnyPermission(explode('|', $rule[1]))
            && ! ($action === 'approve' && $s->submitted_by === $user->id))->keys()->all();
        if (in_array($s->status, [StockCountSession::DRAFT, StockCountSession::COUNTING, StockCountSession::SUBMITTED], true) && $user->can('inventory.counts.create')) {
            $actions[] = 'cancel';
        }

        return [
            'id' => $s->id,
            'count_number' => $s->count_number,
            'warehouse' => Refs::coded($s->warehouse),
            'location_ids' => $s->location_ids,
            'category_ids' => $s->category_ids,
            'item_ids' => $s->item_ids,
            'status' => $s->status,
            'lock_locations' => $s->lock_locations,
            'cutoff_at' => $s->cutoff_at?->toIso8601String(),
            'remarks' => $s->remarks,
            'line_count' => $s->lines_count ?? ($s->relationLoaded('lines') ? $s->lines->count() : null),
            'transaction_id' => $s->transaction_id,
            'created_by' => $s->relationLoaded('creator') ? Refs::user($s->creator) : null,
            'approved_by' => $s->relationLoaded('approver') ? Refs::user($s->approver) : null,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'approved_at' => $s->approved_at?->toIso8601String(),
            'posted_at' => $s->posted_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
            'allowed_actions' => $actions,
        ];
    }
}
