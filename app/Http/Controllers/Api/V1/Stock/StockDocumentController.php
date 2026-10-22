<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\Stock\StockDocument;
use App\Models\User;
use App\Services\Stock\StockDocumentService;
use App\Services\Stock\StockOverview;
use App\Services\Stock\StockPostingService;
use App\Services\Structure\StructureScope;
use App\Support\Csv;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inward Material, Outward Material and Opening Stock entries. The route
 * supplies the type; permissions are "{inward|outward|opening-stock}.{action}".
 */
class StockDocumentController extends Controller
{
    private const SORTABLE = ['number', 'document_date', 'status', 'created_at'];

    private const RELATIONS = ['factory', 'location', 'sku', 'vendor', 'department', 'creator', 'poster', 'reverser', 'canceller'];

    public function __construct(
        private readonly StockDocumentService $documents,
        private readonly StockPostingService $posting,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $type = $this->type($request);
        $query = StockDocument::query()->forCurrentCompany()->with(self::RELATIONS)->where('type', $type);
        $this->scope->applyByFactory($query, $request->user());

        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(job_no, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(recipient, \'\')) LIKE ?', [$like])
                ->orWhereHas('sku', fn ($s) => $s->whereRaw('LOWER(sku) LIKE ?', [$like])->orWhereRaw('LOWER(product_name) LIKE ?', [$like])));
        }
        foreach (['status', 'factory_id', 'location_id', 'sku_id', 'vendor_id', 'department_id'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('document_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('document_date', '<=', $request->date('date_to'));
        }
        if ($request->query('vendor_check') === 'unmapped') {
            $query->where('vendor_mapped', false);
        }
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'document_date';
        $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        if ($request->query('export')) {
            abort_unless($request->user()->can($this->module($type).'.export'), 403);

            return Csv::download("{$type}-material", $this->exportHeaders($type), $query->cursor()->map(fn (StockDocument $d) => $this->exportRow($d)));
        }

        $page = $query->paginate(min(100, $request->integer('per_page', 25) ?: 25))->withQueryString();

        return response()->json(['data' => collect($page->items())->map(fn (StockDocument $d) => $this->row($d, $request->user())), 'meta' => SkuController::meta($page)]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $document = $this->find($request, $id)->load(self::RELATIONS);

        return response()->json(['data' => $this->row($document, $request->user()) + [
            'history' => $document->entries()->with('creator')->orderBy('id')->get()->map(fn ($e) => [
                'id' => $e->id, 'is_reversal' => $e->is_reversal, 'qty_in' => StockOverview::number($e->qty_in),
                'qty_rejected' => StockOverview::number($e->qty_rejected), 'qty_out' => StockOverview::number($e->qty_out),
                'balance_after' => StockOverview::number($e->balance_after), 'posted_at' => $e->posted_at?->toIso8601String(),
                'user' => $e->creator?->full_name,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $this->type($request);
        $document = $this->documents->create($type, $request->all(), $request->user());

        return response()->json(['data' => $this->row($document->load(self::RELATIONS), $request->user()),
            'message' => StockDocumentService::LABELS[$type]." {$document->number} saved as draft."], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $document = $this->documents->update($this->find($request, $id), $request->all(), $request->user());

        return response()->json(['data' => $this->row($document->load(self::RELATIONS), $request->user()), 'message' => 'Draft saved.']);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['vendor_check_reason' => ['nullable', 'string', 'max:500']]);
        $document = $this->posting->post($this->find($request, $id), $request->user(), $data['vendor_check_reason'] ?? null);

        return response()->json(['data' => $this->row($document->load(self::RELATIONS), $request->user()),
            'message' => "{$document->number} posted. Stock updated."]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.required' => 'Give a reason for the reversal.']);
        $document = $this->posting->reverse($this->find($request, $id), $request->user(), $data['reason']);

        return response()->json(['data' => $this->row($document->load(self::RELATIONS), $request->user()),
            'message' => "{$document->number} reversed. Stock restored."]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $document = $this->documents->cancel($this->find($request, $id), $data['reason'] ?? null, $request->user());

        return response()->json(['data' => $this->row($document->load(self::RELATIONS), $request->user()), 'message' => "{$document->number} cancelled."]);
    }

    private function find(Request $request, int $id): StockDocument
    {
        $document = StockDocument::query()->forCurrentCompany()->where('type', $this->type($request))->findOrFail($id);
        $this->scope->ensureFactory($request->user(), $document->factory_id);

        return $document;
    }

    private function type(Request $request): string
    {
        return (string) $request->route('type');
    }

    private function module(string $type): string
    {
        return $type === StockDocument::OPENING ? 'opening-stock' : $type;
    }

    /** @return array<string, mixed> */
    private function row(StockDocument $d, User $user): array
    {
        $module = $this->module($d->type);
        $actions = array_keys(array_filter([
            'edit' => $d->status === StockDocument::DRAFT && $user->can("{$module}.update"),
            'post' => $d->status === StockDocument::DRAFT && $user->can("{$module}.post"),
            'cancel' => $d->status === StockDocument::DRAFT && $user->can("{$module}.update"),
            'reverse' => $d->status === StockDocument::POSTED && $user->can("{$module}.reverse"),
        ]));
        $person = fn (?User $u) => $u ? ['id' => $u->id, 'full_name' => $u->full_name] : null;

        return [
            'id' => $d->id,
            'type' => $d->type,
            'number' => $d->number,
            'document_date' => $d->document_date?->toDateString(),
            'status' => $d->status,
            'factory' => $d->factory ? ['id' => $d->factory->id, 'code' => $d->factory->code, 'name' => $d->factory->name] : null,
            'location' => $d->location ? ['id' => $d->location->id, 'code' => $d->location->code, 'name' => $d->location->name] : null,
            'sku' => $d->sku ? SkuController::rowFor($d->sku) : null,
            'vendor' => $d->vendor ? ['id' => $d->vendor->id, 'code' => $d->vendor->code, 'name' => $d->vendor->name] : null,
            'department' => $d->department ? ['id' => $d->department->id, 'code' => $d->department->code, 'name' => $d->department->name] : null,
            'quantity' => StockOverview::number($d->quantity),
            'received_qty' => StockOverview::number($d->received_qty),
            'rejected_qty' => StockOverview::number($d->rejected_qty),
            'accepted_qty' => StockOverview::number(bcsub((string) $d->received_qty, (string) $d->rejected_qty, 3)),
            'recipient' => $d->recipient,
            'job_no' => $d->job_no,
            'reference' => $d->reference,
            'remarks' => $d->remarks,
            'vendor_mapped' => $d->vendor_mapped,
            'vendor_check_reason' => $d->vendor_check_reason,
            'created_by' => $person($d->creator),
            'created_at' => $d->created_at?->toIso8601String(),
            'posted_by' => $person($d->poster),
            'posted_at' => $d->posted_at?->toIso8601String(),
            'reversed_by' => $person($d->reverser),
            'reversed_at' => $d->reversed_at?->toIso8601String(),
            'reversal_reason' => $d->reversal_reason,
            'cancelled_by' => $person($d->canceller),
            'cancelled_at' => $d->cancelled_at?->toIso8601String(),
            'cancel_reason' => $d->cancel_reason,
            'allowed_actions' => $actions,
            'can_override_vendor_check' => $d->type === StockDocument::INWARD && $user->can('inward.override-vendor-check'),
        ];
    }

    /** @return list<string> */
    private function exportHeaders(string $type): array
    {
        $common = ['Number', 'Date', 'Status', 'Factory', 'Storage Location', 'SKU', 'Product Name', 'Category', 'Material'];

        return match ($type) {
            StockDocument::INWARD => [...$common, 'Vendor', 'Received Qty', 'Rejected Qty', 'Accepted Qty', 'Job No', 'Vendor Ref / Invoice', 'Vendor Check', 'Remarks', 'Created By', 'Posted At'],
            StockDocument::OUTWARD => [...$common, 'Issue Qty', 'Department', 'Recipient', 'Party / Vendor', 'Job No', 'Purpose / Reference', 'Remarks', 'Created By', 'Posted At'],
            default => [...$common, 'Opening Qty', 'Remarks', 'Created By', 'Posted At'],
        };
    }

    /** @return list<mixed> */
    private function exportRow(StockDocument $d): array
    {
        $common = [$d->number, $d->document_date?->toDateString(), ucfirst($d->status), $d->factory?->name, $d->location?->code,
            $d->sku?->sku, $d->sku?->product_name, $d->sku?->category, $d->sku?->material];
        $tail = [$d->remarks, $d->creator?->full_name, $d->posted_at?->toDateTimeString()];

        return match ($d->type) {
            StockDocument::INWARD => [...$common, $d->vendor?->name, StockOverview::number($d->received_qty), StockOverview::number($d->rejected_qty),
                StockOverview::number(bcsub((string) $d->received_qty, (string) $d->rejected_qty, 3)), $d->job_no, $d->reference,
                $d->vendor_mapped === false ? 'Not mapped' : ($d->vendor_mapped ? 'OK' : ''), ...$tail],
            StockDocument::OUTWARD => [...$common, StockOverview::number($d->quantity), $d->department?->name, $d->recipient, $d->vendor?->name,
                $d->job_no, $d->reference, ...$tail],
            default => [...$common, StockOverview::number($d->quantity), ...$tail],
        };
    }
}
