<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\User;
use App\Services\Inventory\Movement;
use Illuminate\Validation\Rule;

/**
 * Return of unused issued material: draft → submitted → approved → posted.
 * Must reference a posted material issue; cannot return more than was
 * issued (minus other returns). Good material needing inspection goes to
 * QC pending; damaged material goes to QC hold — never straight to available.
 */
class MaterialReturnHandler extends DocumentHandler
{
    public function type(): string
    {
        return InventoryDocument::MATERIAL_RETURN;
    }

    public function label(): string
    {
        return 'Material Return';
    }

    public function numberPrefix(): string
    {
        return 'MRT-';
    }

    public function module(): string
    {
        return 'inventory.returns';
    }

    public function workflow(): array
    {
        return [
            'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'inventory.returns.create'],
            'approve' => ['from' => ['submitted'], 'to' => 'approved', 'permission' => 'inventory.returns.approve'],
            'post' => ['from' => ['approved'], 'to' => 'posted', 'permission' => 'inventory.returns.post', 'posts' => true],
            'cancel' => ['from' => ['draft', 'submitted', 'approved'], 'to' => 'cancelled', 'permission' => 'inventory.returns.create'],
        ];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'original_document_id' => ['required', 'integer'],
            'destination_location_id' => ['nullable', 'integer'],
            'reason_code_id' => ['nullable', 'integer', Rule::exists('inventory_reason_codes', 'id')->where('is_active', true)],
            'lines.*.condition' => ['required', Rule::in(['good', 'damaged'])],
            'lines.*.inspection_required' => ['sometimes', 'boolean'],
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $issue = InventoryDocument::query()->with('lines')->find($document->original_document_id);
        if (! $issue || $issue->document_type !== InventoryDocument::MATERIAL_ISSUE || $issue->status !== 'posted') {
            $this->fail('original_document_id', 'Select a posted material issue.');
        }
        $source = $this->location($issue->destination_location_id, 'original_document_id', false);
        $destination = $this->location($document->destination_location_id ?? $issue->source_location_id, 'destination_location_id');
        if ($this->purpose($destination) === 'production') {
            $this->fail('destination_location_id', 'Return to a store location, not a production location.');
        }
        $document->forceFill([
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'warehouse_id' => $destination->warehouse_id,
            'department_id' => $issue->department_id,
        ]);

        $issued = $issue->lines->groupBy('item_id')->map(fn ($g) => $g->reduce(fn ($c, $l) => bcadd($c, (string) $l->base_quantity, 6), '0'));
        $alreadyReturned = DocumentLine::query()
            ->whereHas('document', fn ($q) => $q->where('document_type', InventoryDocument::MATERIAL_RETURN)
                ->where('original_document_id', $issue->id)->where('status', '!=', 'cancelled')
                ->when($document->exists, fn ($w) => $w->whereKeyNot($document->id)))
            ->get(['item_id', 'base_quantity'])->groupBy('item_id')
            ->map(fn ($g) => $g->reduce(fn ($c, $l) => bcadd($c, (string) $l->base_quantity, 6), '0'));

        $requested = [];
        foreach ($lines as $line) {
            $i = $line['_index'];
            $itemId = $line['item']->id;
            if (! $issued->has($itemId)) {
                $this->fail("lines.{$i}.item_id", "{$line['item']->code} was not issued on {$issue->document_number}.");
            }
            $requested[$itemId] = bcadd($requested[$itemId] ?? '0', $line['base_quantity'], 6);
            $eligible = bcsub($issued[$itemId], $alreadyReturned[$itemId] ?? '0', 6);
            if (bccomp($requested[$itemId], $eligible, 6) > 0) {
                $this->fail("lines.{$i}.quantity", "At most {$eligible} {$line['item']->baseUom?->code} of {$line['item']->code} can still be returned against {$issue->document_number}.");
            }
        }

        return array_map(fn (array $line) => [
            ...$line,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'from_status' => 'available',
            'to_status' => match (true) {
                $line['condition'] === 'damaged' => 'qc_hold',
                (bool) ($line['inspection_required'] ?? false) || $line['item']->requires_qc => 'qc_pending',
                default => 'available',
            },
            'condition' => $line['condition'],
        ], $lines);
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        if ($action !== 'post') {
            return [];
        }

        return collect($this->lines($document))->flatMap(fn (DocumentLine $l) => [
            new Movement($l->item_id, $l->source_location_id, 'available', $this->negate((string) $l->base_quantity), null, $l->id, null, "line-{$l->id}"),
            new Movement($l->item_id, $l->destination_location_id, $l->to_status, (string) $l->base_quantity, null, $l->id, null, "line-{$l->id}"),
        ])->all();
    }

    public function afterAction(string $action, InventoryDocument $document, User $user, array $payload): void
    {
        if ($action === 'post') {
            $document->forceFill(['received_by' => $user->id, 'received_at' => now()]);
        }
    }
}
