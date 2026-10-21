<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Services\Inventory\Movement;
use Illuminate\Validation\Rule;

/**
 * Manual QC stock disposition (Module 4 stand-in until Module 8):
 * release (→ available), hold (→ QC hold) or reject (→ rejected) stock
 * that is QC pending / on hold. Created and posted in one step.
 */
class QcDispositionHandler extends DocumentHandler
{
    public const DECISIONS = ['release' => 'available', 'hold' => 'qc_hold', 'reject' => 'rejected'];

    public function type(): string
    {
        return InventoryDocument::QC_DISPOSITION;
    }

    public function label(): string
    {
        return 'QC Disposition';
    }

    public function numberPrefix(): string
    {
        return 'QCD-';
    }

    public function module(): string
    {
        return 'inventory.qc-disposition';
    }

    public function editPermission(): string
    {
        return 'inventory.qc-disposition.release|inventory.qc-disposition.hold|inventory.qc-disposition.reject';
    }

    public function workflow(): array
    {
        return [
            'post' => ['from' => ['draft'], 'to' => 'posted', 'permission' => $this->editPermission(), 'posts' => true],
        ];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'decision' => ['required', Rule::in(array_keys(self::DECISIONS))],
            'reason_code_id' => ['nullable', 'integer', Rule::exists('inventory_reason_codes', 'id')->where('is_active', true)],
            'remarks' => ['required', 'string', 'max:1000'],
            'lines.*.source_location_id' => ['required', 'integer'],
            'lines.*.destination_location_id' => ['nullable', 'integer'],
            'lines.*.from_status' => ['required', Rule::in(['qc_pending', 'qc_hold'])],
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $to = self::DECISIONS[$document->decision];

        return array_map(function (array $line) use ($to, $document) {
            $i = $line['_index'];
            if ($line['from_status'] === $to) {
                $this->fail("lines.{$i}.from_status", 'This stock already has that status.');
            }
            $source = $this->location($line['source_location_id'] ?? null, "lines.{$i}.source_location_id", false);
            $destination = $this->location($line['destination_location_id'] ?? $source->id, "lines.{$i}.destination_location_id");
            $document->warehouse_id ??= $source->warehouse_id;

            return [...$line, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'to_status' => $to];
        }, $lines);
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        return collect($this->lines($document))->flatMap(fn (DocumentLine $l) => [
            new Movement($l->item_id, $l->source_location_id, $l->from_status, $this->negate((string) $l->base_quantity), null, $l->id, null, "line-{$l->id}"),
            new Movement($l->item_id, $l->destination_location_id, $l->to_status, (string) $l->base_quantity, null, $l->id, null, "line-{$l->id}"),
        ])->all();
    }
}
