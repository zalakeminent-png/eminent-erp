<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\InventoryDocument;
use App\Models\MasterData\BusinessPartner;
use App\Services\Inventory\Movement;
use Illuminate\Validation\Rule;

/**
 * Goods receipt: draft → submitted → posted → closed.
 * Items that require QC are received into "qc_pending" stock and are not
 * available until released through a QC disposition.
 */
class GoodsReceiptHandler extends DocumentHandler
{
    public function type(): string
    {
        return InventoryDocument::GRN;
    }

    public function label(): string
    {
        return 'Goods Receipt';
    }

    public function numberPrefix(): string
    {
        return 'GRN-';
    }

    public function module(): string
    {
        return 'inventory.grn';
    }

    public function editPermission(): string
    {
        return 'inventory.grn.create|inventory.grn.update';
    }

    public function workflow(): array
    {
        return [
            'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'inventory.grn.create|inventory.grn.update'],
            'post' => ['from' => ['submitted'], 'to' => 'posted', 'permission' => 'inventory.grn.post', 'posts' => true],
            'close' => ['from' => ['posted'], 'to' => 'closed', 'permission' => 'inventory.grn.post'],
            'cancel' => ['from' => ['draft', 'submitted'], 'to' => 'cancelled', 'permission' => 'inventory.grn.create|inventory.grn.update'],
        ];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('business_partners', 'id')],
            'supplier_invoice_number' => ['nullable', 'string', 'max:80'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'lines.*.destination_location_id' => ['required', 'integer'],
            'lines.*.unit_cost' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $supplier = BusinessPartner::query()->find($document->supplier_id);
        if (! $supplier || ! $supplier->is_supplier || ! $supplier->is_active) {
            $this->fail('supplier_id', 'Select an active supplier.');
        }

        $locations = [];
        $prepared = array_map(function (array $line) use (&$locations) {
            $i = $line['_index'];
            $location = $this->location($line['destination_location_id'] ?? null, "lines.{$i}.destination_location_id");
            $locations[] = [$location, "lines.{$i}.destination_location_id"];

            return [
                ...$line,
                'destination_location_id' => $location->id,
                'to_status' => $line['item']->requires_qc ? 'qc_pending' : 'available',
                'unit_cost' => isset($line['unit_cost']) && $line['unit_cost'] !== '' ? $line['unit_cost'] : null,
            ];
        }, $lines);
        $this->assignFactory($document, $locations);

        return $prepared;
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        if ($action !== 'post') {
            return [];
        }

        return array_map(function ($line) {
            // Cost per base unit = entered unit cost / conversion factor.
            $cost = $line->unit_cost !== null ? bcdiv((string) $line->unit_cost, (string) $line->conversion_factor, 6) : null;

            return new Movement($line->item_id, $line->destination_location_id, $line->to_status, (string) $line->base_quantity, $cost, $line->id, 'supplier');
        }, $this->lines($document));
    }

    public function afterAction(string $action, InventoryDocument $document, $user, array $payload): void
    {
        if ($action === 'post') {
            $document->forceFill(['received_by' => $user->id, 'received_at' => now()]);
        }
    }
}
