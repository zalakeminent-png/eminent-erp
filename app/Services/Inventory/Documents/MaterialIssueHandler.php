<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\User;
use App\Services\Inventory\Movement;

/**
 * Material issue to production: draft → submitted → approved → posted.
 * A physical move from a store location to a production/WIP location —
 * NOT consumption (that is recorded by Production Tracking later).
 */
class MaterialIssueHandler extends DocumentHandler
{
    public function type(): string
    {
        return InventoryDocument::MATERIAL_ISSUE;
    }

    public function label(): string
    {
        return 'Material Issue';
    }

    public function numberPrefix(): string
    {
        return 'MIS-';
    }

    public function module(): string
    {
        return 'inventory.material-issues';
    }

    public function workflow(): array
    {
        return [
            'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'inventory.material-issues.create'],
            'approve' => ['from' => ['submitted'], 'to' => 'approved', 'permission' => 'inventory.material-issues.approve'],
            'post' => ['from' => ['approved'], 'to' => 'posted', 'permission' => 'inventory.material-issues.post', 'posts' => true],
            'cancel' => ['from' => ['draft', 'submitted', 'approved'], 'to' => 'cancelled', 'permission' => 'inventory.material-issues.create'],
        ];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'source_location_id' => ['required', 'integer'],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id'],
            'reference_number' => ['nullable', 'string', 'max:100'], // manual production reference until Module 6
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $source = $this->location($document->source_location_id, 'source_location_id', false);
        $destination = $this->location($document->destination_location_id, 'destination_location_id');
        if ($this->purpose($destination) !== 'production') {
            $this->fail('destination_location_id', 'Material must be issued to a production / WIP location.');
        }
        if ($this->purpose($source) === 'production') {
            $this->fail('source_location_id', 'Issue from a store location; use a transfer to move stock between WIP locations.');
        }
        $document->warehouse_id = $source->warehouse_id;
        $document->department_id = $destination->warehouse->department_id;

        return array_map(fn (array $line) => [
            ...$line,
            'source_location_id' => $source->id,
            'destination_location_id' => $destination->id,
            'from_status' => 'available',
            'to_status' => 'available',
        ], $lines);
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        if ($action !== 'post') {
            return [];
        }

        return collect($this->lines($document))->flatMap(fn (DocumentLine $l) => [
            new Movement($l->item_id, $l->source_location_id, 'available', $this->negate((string) $l->base_quantity), null, $l->id, null, "line-{$l->id}"),
            new Movement($l->item_id, $l->destination_location_id, 'available', (string) $l->base_quantity, null, $l->id, null, "line-{$l->id}"),
        ])->all();
    }

    public function afterAction(string $action, InventoryDocument $document, User $user, array $payload): void
    {
        if ($action === 'post') {
            $document->forceFill(['issued_by' => $user->id, 'issued_at' => now()]);
        }
    }
}
