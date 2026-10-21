<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\User;
use App\Services\Inventory\Movement;
use App\Services\Inventory\StockQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;

/**
 * Stock adjustment: draft → submitted → approved → posted. Lines carry a
 * signed quantity; posting creates compensating ledger entries (history is
 * never edited). Requesters cannot approve their own adjustment.
 */
class AdjustmentHandler extends DocumentHandler
{
    private const ADJUSTABLE = ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap', 'in_transit'];

    public function type(): string
    {
        return InventoryDocument::ADJUSTMENT;
    }

    public function label(): string
    {
        return 'Stock Adjustment';
    }

    public function numberPrefix(): string
    {
        return 'ADJ-';
    }

    public function module(): string
    {
        return 'inventory.adjustments';
    }

    public function workflow(): array
    {
        return [
            'submit' => ['from' => ['draft'], 'to' => 'submitted', 'permission' => 'inventory.adjustments.create'],
            'approve' => ['from' => ['submitted'], 'to' => 'approved', 'permission' => 'inventory.adjustments.approve'],
            'post' => ['from' => ['approved'], 'to' => 'posted', 'permission' => 'inventory.adjustments.post', 'posts' => true],
            'cancel' => ['from' => ['draft', 'submitted', 'approved'], 'to' => 'cancelled', 'permission' => 'inventory.adjustments.create'],
        ];
    }

    public function lineRules(): array
    {
        $rules = parent::lineRules();
        // Signed quantity: + adds stock, − removes stock.
        $rules['lines.*.quantity'] = ['required', 'regex:/^-?\d{1,14}(\.\d{1,6})?$/', 'not_regex:/^-?0+(\.0+)?$/'];

        return $rules;
    }

    public function lineMessages(): array
    {
        return [...parent::lineMessages(), 'lines.*.quantity.regex' => 'Enter the adjustment quantity (+ to add, − to remove).'];
    }

    public function headerRules(?InventoryDocument $document): array
    {
        return [
            'reason_code_id' => ['required', 'integer', Rule::exists('inventory_reason_codes', 'id')->where('is_active', true)],
            'lines.*.source_location_id' => ['required', 'integer'],
            'lines.*.from_status' => ['required', Rule::in(self::ADJUSTABLE)],
            'lines.*.unit_cost' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
        ];
    }

    public function prepareLines(InventoryDocument $document, array $lines): array
    {
        $query = app(StockQuery::class);

        $locations = [];
        $prepared = array_map(function (array $line) use ($query, &$locations) {
            $i = $line['_index'];
            $location = $this->location($line['source_location_id'] ?? null, "lines.{$i}.source_location_id", false);
            $locations[] = [$location, "lines.{$i}.source_location_id"];

            return [
                ...$line,
                'source_location_id' => $location->id,
                'from_status' => $line['from_status'],
                'to_status' => $line['from_status'],
                'system_base_quantity' => $query->onHand($line['item']->id, $location->id, $line['from_status']),
                'unit_cost' => bccomp($line['base_quantity'], '0', 6) > 0 && isset($line['unit_cost']) && $line['unit_cost'] !== '' ? $line['unit_cost'] : null,
            ];
        }, $lines);
        $this->assignFactory($document, $locations);

        return $prepared;
    }

    public function beforeAction(string $action, InventoryDocument $document, User $user, array $payload): void
    {
        if ($action === 'approve' && in_array($user->id, array_filter([$document->requested_by, $document->created_by, $document->submitted_by]), true)) {
            throw new AuthorizationException('You cannot approve a stock adjustment you requested.');
        }
    }

    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        if ($action !== 'post') {
            return [];
        }

        return array_map(fn (DocumentLine $l) => new Movement(
            $l->item_id, $l->source_location_id, $l->from_status, (string) $l->base_quantity,
            $l->unit_cost !== null ? bcdiv((string) $l->unit_cost, (string) $l->conversion_factor, 6) : null,
            $l->id, 'adjustment',
        ), $this->lines($document));
    }

    public function afterAction(string $action, InventoryDocument $document, User $user, array $payload): void
    {
        if ($action === 'submit' || $action === 'post') {
            // Refresh the system quantity snapshot for the record.
            $query = app(StockQuery::class);
            foreach ($document->lines as $line) {
                if ($action === 'submit') {
                    $line->forceFill(['system_base_quantity' => $query->onHand($line->item_id, $line->source_location_id, $line->from_status)])->save();
                }
            }
        }
    }
}
