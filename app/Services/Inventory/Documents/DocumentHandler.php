<?php

namespace App\Services\Inventory\Documents;

use App\Models\Inventory\DocumentLine;
use App\Models\Inventory\InventoryDocument;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\Inventory\Movement;
use App\Services\Inventory\StockQuery;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Per-type rules for stock documents: workflow, validation and the stock
 * movements each action posts. Handlers never write stock themselves.
 */
abstract class DocumentHandler
{
    abstract public function type(): string;

    abstract public function label(): string;

    abstract public function numberPrefix(): string;

    /** Permission module, e.g. "inventory.grn". */
    abstract public function module(): string;

    /**
     * action => [from statuses, to status, permission action, posts stock?]
     *
     * @return array<string, array{from: list<string>, to: string, permission: string, posts?: bool, repeatable?: bool}>
     */
    abstract public function workflow(): array;

    /** Header validation rules (beyond the common ones). */
    abstract public function headerRules(?InventoryDocument $document): array;

    /**
     * Resolves and validates lines, returning attributes for DocumentLine
     * (quantities already converted to base UOM by the service).
     *
     * @param  list<array<string, mixed>>  $lines  normalised lines with item/uom/base_quantity
     * @return list<array<string, mixed>>
     */
    abstract public function prepareLines(InventoryDocument $document, array $lines): array;

    /**
     * Stock movements for an action (empty when the action has no stock effect).
     *
     * @return list<Movement>
     */
    public function movements(string $action, InventoryDocument $document, array $payload): array
    {
        return [];
    }

    /** Extra checks before an action (e.g. segregation of duties). */
    public function beforeAction(string $action, InventoryDocument $document, User $user, array $payload): void {}

    /** Extra header attributes to set when an action completes. */
    public function afterAction(string $action, InventoryDocument $document, User $user, array $payload): void {}

    /** Permission needed to create/edit drafts. */
    public function editPermission(): string
    {
        return $this->module().'.create';
    }

    /** Line rules shared by all types. */
    public function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,14}(\.\d{1,6})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function lineMessages(): array
    {
        return [
            'lines.required' => 'Add at least one line.',
            'lines.*.quantity.regex' => 'Enter a positive quantity (max 6 decimals).',
            'lines.*.quantity.not_regex' => 'Quantity must be greater than zero.',
        ];
    }

    // ---- helpers ---------------------------------------------------------

    protected function location(?int $id, string $field, bool $mustBeActive = true): WarehouseLocation
    {
        $location = $id ? WarehouseLocation::query()->with('warehouse')->find($id) : null;
        if (! $location) {
            throw ValidationException::withMessages([$field => 'Select a valid location.']);
        }
        if ($mustBeActive && (! $location->is_active || ! $location->warehouse->is_active)) {
            throw ValidationException::withMessages([$field => "Location {$location->code} or its warehouse is inactive."]);
        }

        return $location;
    }

    /**
     * Warehouses are internal: a document belongs to the factory of its
     * line locations, which must all be in one factory. The first line's
     * internal warehouse becomes the document's.
     *
     * @param  list<array{0: WarehouseLocation, 1: string}>  $locations  [location, error field]
     */
    protected function assignFactory(InventoryDocument $document, array $locations): void
    {
        $factoryId = $locations[0][0]->warehouse->factory_id;
        foreach ($locations as [$location, $field]) {
            if ($location->warehouse->factory_id !== $factoryId) {
                $this->fail($field, 'All lines must be in the same factory.');
            }
        }
        $document->warehouse_id = $locations[0][0]->warehouse_id;
    }

    protected function purpose(WarehouseLocation $location): string
    {
        return StockQuery::purpose($location);
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    protected function negate(string $quantity): string
    {
        return bcmul($quantity, '-1', 6);
    }

    /** @return list<DocumentLine> */
    protected function lines(InventoryDocument $document): array
    {
        return $document->lines()->with('item')->get()->all();
    }
}
