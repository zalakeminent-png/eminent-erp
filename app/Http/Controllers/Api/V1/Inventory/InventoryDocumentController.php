<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryDocumentResource;
use App\Http\Resources\MasterData\DocumentResource;
use App\Models\Inventory\InventoryDocument;
use App\Services\Inventory\Documents\DocumentService;
use App\Services\MasterData\DocumentStorage;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * One controller for every stock document type (GRN, transfer, issue,
 * return, adjustment, scrap, QC disposition). The route supplies the type;
 * the type handler defines workflow, validation and posting rules.
 */
class InventoryDocumentController extends Controller
{
    private const SORTABLE = ['document_number', 'document_date', 'status', 'created_at', 'updated_at'];

    private const DETAIL_RELATIONS = [
        'factory', 'warehouse', 'sourceLocation.warehouse', 'destinationLocation.warehouse', 'department', 'supplier', 'originalDocument',
        'reasonCode', 'requester', 'approver', 'issuer', 'receiver', 'poster', 'creator', 'documents.uploader',
        'lines.item', 'lines.uom', 'lines.baseUom', 'lines.sourceLocation.warehouse', 'lines.destinationLocation.warehouse', 'events.user',
    ];

    public function __construct(
        private readonly DocumentService $documents,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = InventoryDocument::query()
            ->where('document_type', $this->type($request))
            ->with(['factory', 'warehouse', 'sourceLocation.warehouse', 'destinationLocation.warehouse', 'supplier', 'reasonCode', 'requester', 'department'])
            ->withCount('lines');
        $this->scope->applyByFactory($query, $request->user());

        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(document_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(reference_number, \'\')) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(supplier_invoice_number, \'\')) LIKE ?', [$like])
                ->orWhereHas('lines.item', fn ($i) => $i->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like])));
        }
        foreach (['status', 'warehouse_id', 'factory_id', 'supplier_id', 'department_id', 'reason_code_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('location_id')) {
            $id = $request->integer('location_id');
            $query->where(fn ($q) => $q->where('source_location_id', $id)->orWhere('destination_location_id', $id)
                ->orWhereHas('lines', fn ($l) => $l->where('source_location_id', $id)->orWhere('destination_location_id', $id)));
        }
        if ($request->filled('item_id')) {
            $query->whereHas('lines', fn ($l) => $l->where('item_id', $request->integer('item_id')));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('document_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('document_date', '<=', $request->date('date_to'));
        }
        if ($request->boolean('pending')) {
            $query->whereNotIn('status', ['posted', 'received', 'closed', 'cancelled']);
        }

        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return InventoryDocumentResource::collection($query->paginate(min(100, $request->integer('per_page', 15) ?: 15))->withQueryString());
    }

    public function show(Request $request, int $id): InventoryDocumentResource
    {
        return new InventoryDocumentResource($this->find($request, $id)->load(self::DETAIL_RELATIONS));
    }

    public function store(Request $request): JsonResponse
    {
        $document = $this->documents->create($this->type($request), $request->all(), $request->user());

        return (new InventoryDocumentResource($document->load(self::DETAIL_RELATIONS)))
            ->additional(['message' => "{$this->documents->handler($this->type($request))->label()} {$document->document_number} created."])
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id): InventoryDocumentResource
    {
        $document = $this->documents->update($this->find($request, $id), $request->all(), $request->user());

        return (new InventoryDocumentResource($document->load(self::DETAIL_RELATIONS)))->additional(['message' => 'Saved.']);
    }

    public function action(Request $request, int $id, string $action): InventoryDocumentResource
    {
        $payload = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'receipts' => ['sometimes', 'array', 'max:500'],
            'receipts.*.line_id' => ['required', 'integer'],
            'receipts.*.base_quantity' => ['required', 'string', 'max:30'],
        ]);
        $document = $this->documents->act($this->find($request, $id), $action, $payload, $request->user());

        return (new InventoryDocumentResource($document->load(self::DETAIL_RELATIONS)))->additional(['message' => 'Done.']);
    }

    public function storeAttachment(Request $request, DocumentStorage $storage, int $id): JsonResponse
    {
        $document = $this->find($request, $id);
        abort_unless($this->documents->can($request->user(), $this->documents->handler($this->type($request))->editPermission()), 403);
        $config = config('erp.master_data.documents');
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:'.$config['max_kb'], 'extensions:'.implode(',', $config['extensions'])],
        ], ['file.extensions' => 'This file type is not allowed.']);

        $attachment = $storage->store($document, $request->file('file'), 'other', $data['title'] ?? null, null, $request->user());

        return (new DocumentResource($attachment->load('uploader')))->additional(['message' => 'Attachment uploaded.'])->response()->setStatusCode(201);
    }

    private function find(Request $request, int $id): InventoryDocument
    {
        $document = InventoryDocument::query()->where('document_type', $this->type($request))->findOrFail($id);
        $this->scope->ensureFactory($request->user(), $document->factory_id);

        return $document;
    }

    /** Document type supplied by the route definition. */
    private function type(Request $request): string
    {
        return (string) $request->route('type');
    }

    /** Workflow actions accepted by the action route. */
    public static function actionPattern(): string
    {
        return implode('|', ['submit', 'approve', 'post', 'issue', 'transfer', 'receive', 'close', 'cancel']);
    }
}
