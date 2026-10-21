<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentEvent;
use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\Inventory\PostingContext;
use App\Services\Inventory\StockPostingService;
use App\Services\MasterData\NumberSequence;
use App\Services\MasterData\UomConverter;
use App\Services\Structure\StructureScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates, edits and moves stock documents through their workflow. All
 * stock effects go through StockPostingService with deterministic
 * idempotency keys, so a repeated request never posts twice.
 */
class DocumentService
{
    /** @var array<string, class-string<DocumentHandler>> */
    public const HANDLERS = [
        InventoryDocument::GRN => GoodsReceiptHandler::class,
        InventoryDocument::TRANSFER => TransferHandler::class,
        InventoryDocument::MATERIAL_ISSUE => MaterialIssueHandler::class,
        InventoryDocument::MATERIAL_RETURN => MaterialReturnHandler::class,
        InventoryDocument::ADJUSTMENT => AdjustmentHandler::class,
        InventoryDocument::SCRAP => ScrapHandler::class,
        InventoryDocument::QC_DISPOSITION => QcDispositionHandler::class,
    ];

    private const LINE_COLUMNS = [
        'item_id', 'quantity', 'uom_id', 'conversion_factor', 'base_quantity', 'base_uom_id', 'source_location_id',
        'destination_location_id', 'from_status', 'to_status', 'unit_cost', 'system_base_quantity', 'condition',
        'reason_code_id', 'remarks',
    ];

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly UomConverter $converter,
        private readonly NumberSequence $numbers,
        private readonly StructureScope $scope,
        private readonly ActivityLogger $logger,
    ) {}

    public function handler(string $type): DocumentHandler
    {
        $class = self::HANDLERS[$type] ?? throw new \InvalidArgumentException("Unknown document type {$type}.");

        return app($class);
    }

    public function can(User $user, string $permissions): bool
    {
        return $user->hasAnyPermission(explode('|', $permissions));
    }

    /**
     * Actions the user may take on the document now (for the UI; every
     * action is still fully re-checked when it is performed).
     *
     * @return list<string>
     */
    public function allowedActions(InventoryDocument $document, User $user): array
    {
        $handler = $this->handler($document->document_type);
        $actions = [];
        if ($document->status === 'draft' && $this->can($user, $handler->editPermission())) {
            $actions[] = 'edit';
        }
        foreach ($handler->workflow() as $action => $step) {
            if (! in_array($document->status, $step['from'], true) || ! $this->can($user, $step['permission'])) {
                continue;
            }
            if ($action === 'transfer' && $document->requires_handover || $action === 'issue' && ! $document->requires_handover) {
                continue;
            }
            if ($action === 'approve' && $document->document_type === InventoryDocument::ADJUSTMENT
                && in_array($user->id, array_filter([$document->requested_by, $document->created_by, $document->submitted_by]), true)) {
                continue;
            }
            $actions[] = $action;
        }

        return $actions;
    }

    public function create(string $type, array $data, User $user): InventoryDocument
    {
        $handler = $this->handler($type);
        $this->authorize($user, $handler->editPermission());

        return DB::transaction(function () use ($type, $data, $user, $handler) {
            $document = new InventoryDocument(['document_date' => now()->toDateString()]);
            $document->document_type = $type;
            $document->status = 'draft';
            $document->requested_by = $user->id;
            $this->fill($document, $handler, $data, $user);
            $document->document_number = $this->numbers->formatted("inventory-{$type}", $handler->numberPrefix(), 6);
            $document->save();
            $this->saveLines($document);
            $this->event($document, 'created', null, 'draft', $user);
            $this->logger->log('inventory.document.created', $document, ['type' => $type, 'number' => $document->document_number], "{$handler->label()} {$document->document_number} created", $user);

            return $document->refresh();
        });
    }

    public function update(InventoryDocument $document, array $data, User $user): InventoryDocument
    {
        $handler = $this->handler($document->document_type);
        $this->authorize($user, $handler->editPermission());
        $this->scope->ensureFactory($user, $document->factory_id);

        return DB::transaction(function () use ($document, $data, $user, $handler) {
            $document = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'draft') {
                throw new ConflictHttpException('Only draft documents can be edited.');
            }
            $this->fill($document, $handler, $data, $user);
            $document->save();
            $document->lines()->delete();
            $this->saveLines($document);
            $this->event($document, 'updated', 'draft', 'draft', $user);
            $this->logger->log('inventory.document.updated', $document, ['type' => $document->document_type], "{$handler->label()} {$document->document_number} updated", $user);

            return $document->refresh();
        });
    }

    /**
     * Runs a workflow action. Posting actions are idempotent: the key is
     * derived from the document and action (plus a client key for
     * repeatable actions such as partial receipts).
     */
    public function act(InventoryDocument $document, string $action, array $payload, User $user): InventoryDocument
    {
        $handler = $this->handler($document->document_type);
        $step = $handler->workflow()[$action] ?? throw ValidationException::withMessages(['action' => "{$action} is not a valid action for a {$handler->label()}."]);
        $this->authorize($user, $step['permission']);
        $this->scope->ensureFactory($user, $document->factory_id);

        if ($action === 'cancel' && empty($payload['remarks'])) {
            throw ValidationException::withMessages(['remarks' => 'Give a reason for cancelling.']);
        }

        $key = "{$document->document_type}:{$document->id}:{$action}";
        if ($step['repeatable'] ?? false) {
            $client = (string) ($payload['idempotency_key'] ?? '');
            if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $client)) {
                throw ValidationException::withMessages(['idempotency_key' => 'A request key is required for this action.']);
            }
            $key .= ":{$client}";
        }

        return DB::transaction(function () use ($document, $action, $payload, $user, $handler, $step, $key) {
            $document = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            // A retried request that already succeeded: return the current state.
            if (($step['posts'] ?? false) && $document->events()->whereHas('transaction', fn ($q) => $q->where('idempotency_key', $key))->exists()) {
                return $document;
            }
            if (! in_array($document->status, $step['from'], true)) {
                throw new ConflictHttpException("This {$handler->label()} is {$document->status}; it cannot be {$this->past($action)}.");
            }

            $handler->beforeAction($action, $document, $user, $payload);

            $transaction = null;
            if ($step['posts'] ?? false) {
                $transaction = $this->posting->post($document->document_type, $handler->movements($action, $document, $payload), new PostingContext(
                    idempotencyKey: $key,
                    userId: $user->id,
                    sourceDocumentType: $document->document_type,
                    sourceDocumentId: $document->id,
                    remarks: "{$document->document_number} {$action}".(! empty($payload['remarks']) ? ': '.$payload['remarks'] : ''),
                ));
            }

            $from = $document->status;
            $document->status = $step['to'];
            $stamp = match ($action) {
                'submit' => ['submitted_by' => $user->id, 'submitted_at' => now()],
                'approve' => ['approved_by' => $user->id, 'approved_at' => now()],
                'post' => ['posted_by' => $user->id, 'posted_at' => now()],
                'close', 'cancel' => ['closed_at' => now()],
                default => [],
            };
            $document->forceFill($stamp);
            $handler->afterAction($action, $document, $user, $payload);
            $document->updated_by = $user->id;
            $document->save();

            $this->event($document, $action, $from, $document->status, $user, $transaction?->id, $payload['remarks'] ?? null,
                $transaction ? ['transaction_number' => $transaction->transaction_number] : null);
            $this->logger->log("inventory.document.{$action}", $document, [
                'type' => $document->document_type, 'number' => $document->document_number, 'from' => $from, 'to' => $document->status,
                'transaction' => $transaction?->transaction_number,
            ], "{$handler->label()} {$document->document_number}: {$action}", $user);

            return $document->refresh();
        });
    }

    // ---- internals ---------------------------------------------------------

    private function fill(InventoryDocument $document, DocumentHandler $handler, array $data, User $user): void
    {
        $rules = [
            'document_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            ...$handler->lineRules(),
            ...$handler->headerRules($document->exists ? $document : null),
        ];
        $valid = Validator::make($data, $rules, $handler->lineMessages())->validate();

        $document->fill(collect($valid)->only($document->getFillable())->all());
        $document->updated_by = $user->id;

        $lines = $this->normaliseLines($data['lines'] ?? []);
        $prepared = $handler->prepareLines($document, $lines);

        // Factory comes from the warehouse; every touched location must be in scope.
        $warehouse = Warehouse::query()->find($document->warehouse_id);
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'Select a warehouse.']);
        }
        $document->factory_id = $warehouse->factory_id;
        $locationIds = collect($prepared)->flatMap(fn ($l) => [$l['source_location_id'] ?? null, $l['destination_location_id'] ?? null])
            ->push($document->source_location_id, $document->destination_location_id)->filter()->unique();
        $factoryIds = WarehouseLocation::query()->whereKey($locationIds)->with('warehouse:id,factory_id')->get()
            ->map(fn ($l) => $l->warehouse->factory_id)->push($warehouse->factory_id)->unique();
        foreach ($factoryIds as $factoryId) {
            $this->scope->ensureFactory($user, $factoryId);
        }

        $document->setRelation('pendingLines', collect($prepared));
    }

    /**
     * Resolves items/UOMs and converts each quantity to the item's base
     * UOM (exactly; a conversion needing rounding is rejected).
     *
     * @return list<array<string, mixed>>
     */
    private function normaliseLines(array $lines): array
    {
        $items = Item::query()->with('baseUom')->whereKey(collect($lines)->pluck('item_id'))->get()->keyBy('id');
        $uoms = UnitOfMeasure::query()->whereKey(collect($lines)->pluck('uom_id'))->get()->keyBy('id');
        $out = [];

        foreach (array_values($lines) as $i => $line) {
            $item = $items->get((int) $line['item_id']);
            $uom = $uoms->get((int) $line['uom_id']);
            if (! $item->is_active || ! $item->is_stock_item || ! $item->track_inventory) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->code} is inactive or not a tracked stock item."]);
            }
            if (! $item->baseUom) {
                throw ValidationException::withMessages(["lines.{$i}.item_id" => "{$item->code} has no base unit of measure."]);
            }
            $quantity = (string) $line['quantity'];
            $negative = str_starts_with($quantity, '-');
            try {
                $converted = $this->converter->convert(ltrim($quantity, '-'), $uom, $item->baseUom, $item);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(["lines.{$i}.uom_id" => collect($e->errors())->flatten()->first()]);
            }
            if (bccomp($converted['quantity'], '0', 6) === 0) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => 'The quantity is zero in the base unit.']);
            }

            $out[] = [
                ...$line,
                '_index' => $i,
                'item' => $item,
                'item_id' => $item->id,
                'uom_id' => $uom->id,
                'quantity' => $quantity,
                'conversion_factor' => $converted['factor'],
                'base_quantity' => ($negative ? '-' : '').$converted['quantity'],
                'base_uom_id' => $item->base_uom_id,
            ];
        }

        return $out;
    }

    private function saveLines(InventoryDocument $document): void
    {
        foreach ($document->getRelation('pendingLines')->values() as $n => $line) {
            $model = new DocumentLine(collect($line)->only(self::LINE_COLUMNS)->all());
            $model->document_id = $document->id;
            $model->line_no = $n + 1;
            $model->save();
        }
        $document->unsetRelation('pendingLines');
    }

    private function event(InventoryDocument $document, string $action, ?string $from, ?string $to, User $user, ?int $transactionId = null, ?string $remarks = null, ?array $details = null): void
    {
        DocumentEvent::create([
            'document_id' => $document->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'transaction_id' => $transactionId,
            'details' => $details,
            'remarks' => $remarks ? mb_substr($remarks, 0, 1000) : null,
            'user_id' => $user->id,
        ]);
    }

    private function authorize(User $user, string $permissions): void
    {
        if (! $this->can($user, $permissions)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }

    private function past(string $action): string
    {
        return match ($action) {
            'submit' => 'submitted', 'approve' => 'approved', 'post' => 'posted', 'issue' => 'issued',
            'receive' => 'received', 'close' => 'closed', 'cancel' => 'cancelled', 'transfer' => 'transferred',
            default => $action.'ed',
        };
    }
}
