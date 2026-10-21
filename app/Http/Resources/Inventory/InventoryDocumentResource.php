<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\MasterData\DocumentResource;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\DocumentEvent;
use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Services\Inventory\Documents\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryDocument */
class InventoryDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->toDateString(),
            'status' => $this->status,
            'factory' => $this->whenLoaded('factory', fn () => Refs::coded($this->factory)),
            'warehouse' => $this->whenLoaded('warehouse', fn () => Refs::coded($this->warehouse)),
            'source_location' => $this->whenLoaded('sourceLocation', fn () => InventoryRefs::location($this->sourceLocation)),
            'destination_location' => $this->whenLoaded('destinationLocation', fn () => InventoryRefs::location($this->destinationLocation)),
            'department' => $this->whenLoaded('department', fn () => Refs::coded($this->department)),
            'supplier' => $this->whenLoaded('supplier', fn () => Refs::coded($this->supplier)),
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'reference_number' => $this->reference_number,
            'production_order_id' => $this->production_order_id,
            'original_document' => $this->whenLoaded('originalDocument', fn () => $this->originalDocument
                ? ['id' => $this->originalDocument->id, 'document_number' => $this->originalDocument->document_number] : null),
            'reason_code' => $this->whenLoaded('reasonCode', fn () => Refs::coded($this->reasonCode)),
            'requires_handover' => $this->requires_handover,
            'decision' => $this->decision,
            'remarks' => $this->remarks,
            'line_count' => $this->whenCounted('lines'),
            'requested_by' => $this->whenLoaded('requester', fn () => Refs::user($this->requester)),
            'approved_by' => $this->whenLoaded('approver', fn () => Refs::user($this->approver)),
            'issued_by' => $this->whenLoaded('issuer', fn () => Refs::user($this->issuer)),
            'received_by' => $this->whenLoaded('receiver', fn () => Refs::user($this->receiver)),
            'posted_by' => $this->whenLoaded('poster', fn () => Refs::user($this->poster)),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (DocumentLine $l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'item' => $l->relationLoaded('item') ? Refs::coded($l->item) : null,
                'quantity' => Refs::decimal($l->quantity),
                'uom' => $l->relationLoaded('uom') ? Refs::uom($l->uom) : null,
                'conversion_factor' => Refs::decimal($l->conversion_factor),
                'base_quantity' => Refs::decimal($l->base_quantity),
                'base_uom' => $l->relationLoaded('baseUom') ? Refs::uom($l->baseUom) : null,
                'source_location' => $l->relationLoaded('sourceLocation') ? InventoryRefs::location($l->sourceLocation) : null,
                'destination_location' => $l->relationLoaded('destinationLocation') ? InventoryRefs::location($l->destinationLocation) : null,
                'from_status' => $l->from_status,
                'to_status' => $l->to_status,
                'unit_cost' => Refs::decimal($l->unit_cost),
                'system_base_quantity' => Refs::decimal($l->system_base_quantity),
                'new_base_quantity' => $l->system_base_quantity !== null ? Refs::decimal(bcadd((string) $l->system_base_quantity, (string) $l->base_quantity, 6)) : null,
                'received_base_quantity' => Refs::decimal($l->received_base_quantity),
                'outstanding_base_quantity' => Refs::decimal(bcsub((string) $l->base_quantity, (string) $l->received_base_quantity, 6)),
                'condition' => $l->condition,
                'remarks' => $l->remarks,
            ])->values()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (DocumentEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'transaction_number' => $e->details['transaction_number'] ?? null,
                'remarks' => $e->remarks,
                'user' => $e->relationLoaded('user') ? Refs::user($e->user) : null,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values()),
            'attachments' => $this->whenLoaded('documents', fn () => DocumentResource::collection($this->documents)),
            'allowed_actions' => $this->when($request->user() !== null, fn () => app(DocumentService::class)->allowedActions($this->resource, $request->user())),
            ...Refs::audit($this->resource),
        ];
    }
}
