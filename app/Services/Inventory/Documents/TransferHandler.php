<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\User;
use App\Services\Inventory\Movement;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Internal transfer: draft → submitted → approved → in_transit →
 * (partially_received) → received → closed. Issuing moves stock from the
 * source into "in_transit" at the destination; receiving moves it into the
 * destination. Shortages stay visible in transit until corrected — they are
 * never written off automatically. Transfers without handover post
 * source → destination in one step ("transfer").
 */
class TransferHandler extends DocumentHandler
{
    private const MOVABLE = ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap'];

    public function type(): string
    {
        return InventoryDocument::TRANSFER;
    }

    public function label(): string
    {
        return 'Stock Transfer';
    }

    public function numberPrefix(): string
    {
        return 'TRF-';
    }

    public function module(): string
    {
        return 'inventory.transfers';
    }

    public function workflow(): array
    {
        return [
            'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'inventory.transfers.create'],
            'approve' => ['from' => ['submitted'], 'to' => 'approved', 'permission' => 'inventory.transfers.approve'],
            'issue' => ['from' => ['approved'], 'to' => 'in_transit', 'permission' => 'inventory.transfers.issue', 'posts' => true],
            'transfer' => ['from' => ['approved'], 'to' => 'received', 'permission' => 'inventory.transfers.issue', 'posts' => true],
            'receive' => ['from' => ['in_transit', 'partially_received'], 'to' => 'received', 'permission' => 'inventory.transfers.receive', 'posts' => true, 'repeatable' => true],
            'close' => ['from' => ['received', 'partially_received'], 'to' => 'closed', 'permission' => 'inventory.transfers.receive'],
            'cancel' => ['from' => ['draft', 'submitted', 'approved'], 'to' => 'cancelled', 'permission' => 'inventory.transfers.create'],
        ];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'source_location_id' => ['required', 'integer'],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id'],
            'requires_handover' => ['sometimes', 'boolean'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'lines.*.from_status' => ['nullable', Rule::in(self::MOVABLE)],
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $source = $this->location($document->source_location_id, 'source_location_id', false);
        $destination = $this->location($document->destination_location_id, 'destination_location_id');
        if ($source->id === $destination->id) {
            $this->fail('destination_location_id', 'Source and destination must be different.');
        }
        $document->warehouse_id = $source->warehouse_id;

        return array_map(fn (array $line) => [
            ...$line,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'from_status' => $line['from_status'] ?? 'available',
            'to_status' => $line['from_status'] ?? 'available',
        ], $lines);
    }

    public function beforeAction(string $action, InventoryDocument $document, User $user, array $payload): void
    {
        if ($action === 'transfer' && $document->requires_handover) {
            $this->fail('action', 'This transfer requires a handover: issue it, then receive it at the destination.');
        }
        if ($action === 'issue' && ! $document->requires_handover) {
            $this->fail('action', 'This transfer has no handover; post it directly.');
        }
        if ($action === 'close' && $document->status === 'partially_received' && empty($payload['remarks'])) {
            $this->fail('remarks', 'Explain the shortage when closing a partially received transfer. The missing quantity stays in transit until it is corrected by an adjustment.');
        }
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        $lines = $this->lines($document);

        return match ($action) {
            'issue' => collect($lines)->flatMap(fn (DocumentLine $l) => [
                new Movement($l->item_id, $l->source_location_id, $l->from_status, $this->negate((string) $l->base_quantity), null, $l->id, null, "line-{$l->id}"),
                new Movement($l->item_id, $l->destination_location_id, 'in_transit', (string) $l->base_quantity, null, $l->id, null, "line-{$l->id}"),
            ])->all(),
            'transfer' => collect($lines)->flatMap(fn (DocumentLine $l) => [
                new Movement($l->item_id, $l->source_location_id, $l->from_status, $this->negate((string) $l->base_quantity), null, $l->id, null, "line-{$l->id}"),
                new Movement($l->item_id, $l->destination_location_id, $l->to_status, (string) $l->base_quantity, null, $l->id, null, "line-{$l->id}"),
            ])->all(),
            'receive' => $this->receiptMovements($lines, $payload),
            default => [],
        };
    }

    /**
     * @param  list<DocumentLine>  $lines
     * @return list<Movement>
     */
    private function receiptMovements(array $lines, array $payload): array
    {
        $byId = collect($lines)->keyBy('id');
        $movements = [];
        foreach ($payload['receipts'] ?? [] as $i => $receipt) {
            $line = $byId->get((int) ($receipt['line_id'] ?? 0));
            $qty = (string) ($receipt['base_quantity'] ?? '');
            if (! $line) {
                $this->fail("receipts.{$i}.line_id", 'Unknown transfer line.');
            }
            if (! Decimal::isPositive($qty) || Decimal::decimals($qty) > 6) {
                $this->fail("receipts.{$i}.base_quantity", 'Enter a positive received quantity.');
            }
            $outstanding = bcsub((string) $line->base_quantity, (string) $line->received_base_quantity, 6);
            if (bccomp($qty, $outstanding, 6) > 0) {
                $this->fail("receipts.{$i}.base_quantity", "Only {$outstanding} {$line->item->code} is outstanding on this line.");
            }
            $movements[] = new Movement($line->item_id, $line->destination_location_id, 'in_transit', $this->negate($qty), null, $line->id, null, "rcv-{$line->id}");
            $movements[] = new Movement($line->item_id, $line->destination_location_id, $line->to_status, $qty, null, $line->id, null, "rcv-{$line->id}");
        }
        if (! $movements) {
            $this->fail('receipts', 'Enter the received quantities.');
        }

        return $movements;
    }

    public function afterAction(string $action, InventoryDocument $document, User $user, array $payload): void
    {
        if ($action === 'issue' || $action === 'transfer') {
            $document->forceFill(['issued_by' => $user->id, 'issued_at' => now()]);
        }
        if ($action === 'transfer') {
            $document->lines()->update(['received_base_quantity' => DB::raw('base_quantity')]);
            $document->forceFill(['received_by' => $user->id, 'received_at' => now()]);
        }
        if ($action === 'receive') {
            foreach ($payload['receipts'] as $receipt) {
                $line = $document->lines()->whereKey($receipt['line_id'])->first();
                $line->forceFill(['received_base_quantity' => bcadd((string) $line->received_base_quantity, (string) $receipt['base_quantity'], 6)])->save();
            }
            $complete = $document->lines()->get()->every(fn (DocumentLine $l) => bccomp((string) $l->received_base_quantity, (string) $l->base_quantity, 6) >= 0);
            $document->status = $complete ? 'received' : 'partially_received';
            $document->forceFill(['received_by' => $user->id, 'received_at' => now()]);
        }
    }
}
