<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Services\Inventory\Movement;
use Illuminate\Validation\Rule;

/**
 * Scrap / rejection / rework movement and authorised recovery:
 * draft → submitted → approved (posts). Moves stock between statuses
 * (and optionally locations) with a mandatory reason code.
 */
class ScrapHandler extends DocumentHandler
{
    private const FROM = ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected'];

    private const TO = ['scrap', 'rejected', 'rework', 'available', 'qc_pending'];

    public function type(): string
    {
        return InventoryDocument::SCRAP;
    }

    public function label(): string
    {
        return 'Scrap / Rejection';
    }

    public function numberPrefix(): string
    {
        return 'SCR-';
    }

    public function module(): string
    {
        return 'inventory.scrap';
    }

    public function workflow(): array
    {
        return [
            'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'inventory.scrap.create'],
            'approve' => ['from' => ['submitted'], 'to' => 'posted', 'permission' => 'inventory.scrap.approve', 'posts' => true],
            'cancel' => ['from' => ['draft', 'submitted'], 'to' => 'cancelled', 'permission' => 'inventory.scrap.create'],
        ];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'reason_code_id' => ['required', 'integer', Rule::exists('inventory_reason_codes', 'id')->where('is_active', true)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'lines.*.source_location_id' => ['required', 'integer'],
            'lines.*.destination_location_id' => ['nullable', 'integer'],
            'lines.*.from_status' => ['required', Rule::in(self::FROM)],
            'lines.*.to_status' => ['required', Rule::in(self::TO)],
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $prepared = array_map(function (array $line) {
            $i = $line['_index'];
            if ($line['from_status'] === $line['to_status']) {
                $this->fail("lines.{$i}.to_status", 'Choose a different target status.');
            }
            $source = $this->location($line['source_location_id'] ?? null, "lines.{$i}.source_location_id", false);
            $destination = $this->location($line['destination_location_id'] ?? $source->id, "lines.{$i}.destination_location_id");

            return [...$line, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id];
        }, $lines);

        $first = $this->location($prepared[0]['source_location_id'], 'lines.0.source_location_id', false);
        $document->warehouse_id = $first->warehouse_id;

        return $prepared;
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        if ($action !== 'approve') {
            return [];
        }

        return collect($this->lines($document))->flatMap(fn (DocumentLine $l) => [
            new Movement($l->item_id, $l->source_location_id, $l->from_status, $this->negate((string) $l->base_quantity), null, $l->id, null, "line-{$l->id}"),
            new Movement($l->item_id, $l->destination_location_id, $l->to_status, (string) $l->base_quantity, null, $l->id, null, "line-{$l->id}"),
        ])->all();
    }

    public function afterAction(string $action, InventoryDocument $document, $user, array $payload): void
    {
        if ($action === 'approve') {
            $document->forceFill(['posted_by' => $user->id, 'posted_at' => now()]);
        }
    }
}
