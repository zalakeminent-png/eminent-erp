<?php

namespace App\Services\MasterData;

use App\Models\MasterData\Item;
use App\Models\MasterData\ItemUomConversion;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\MasterData\UomConversion;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Unit conversion engine.
 *
 * Universal conversions link units of the same convertible dimension
 * (mass, length, area, volume, time). Item-specific conversions link an
 * alternate unit to an item's base UOM (e.g. 1 BOX = 100 PCS for one item,
 * or a documented weight-per-piece). Factors chain through a graph, so
 * 1 KG = 1000 GM and 1 GM = … resolve transitively. All arithmetic is exact.
 *
 * Future inventory transactions should store the entered quantity, entered
 * UOM and the factor returned here, plus the quantity in the base UOM.
 */
class UomConverter
{
    /**
     * Factor such that 1 $from = factor × $to, or null when not convertible.
     */
    public function factor(int $fromId, int $toId, ?Item $item = null, ?int $ignoreUniversalId = null, ?int $ignoreItemConversionId = null): ?string
    {
        if ($fromId === $toId) {
            return '1';
        }

        $graph = $this->graph($item, $ignoreUniversalId, $ignoreItemConversionId);
        $visited = [$fromId => '1'];
        $queue = [$fromId];

        while ($queue) {
            $node = array_shift($queue);
            foreach ($graph[$node] ?? [] as $next => $factor) {
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = Decimal::mul($visited[$node], $factor);
                if ($next === $toId) {
                    return $visited[$next];
                }
                $queue[] = $next;
            }
        }

        return null;
    }

    public function canConvert(int $fromId, int $toId, ?Item $item = null): bool
    {
        return $this->factor($fromId, $toId, $item) !== null;
    }

    /**
     * Converts $quantity between units. With $strict, a result needing more
     * decimals than the target unit's precision raises an error instead of
     * being silently rounded.
     *
     * @return array{quantity: string, factor: string}
     */
    public function convert(string $quantity, UnitOfMeasure $from, UnitOfMeasure $to, ?Item $item = null, bool $strict = true): array
    {
        $factor = $this->factor($from->id, $to->id, $item);
        if ($factor === null) {
            throw ValidationException::withMessages(['uom_id' => "No conversion is defined from {$from->code} to {$to->code}".($item ? " for item {$item->code}." : '.')]);
        }

        $exact = Decimal::mul($quantity, $factor);
        if (Decimal::decimals($exact) > $to->decimal_precision) {
            if ($strict) {
                throw ValidationException::withMessages([
                    'quantity' => "{$quantity} {$from->code} = {$exact} {$to->code}, which exceeds the {$to->decimal_precision}-decimal precision of {$to->code}.",
                ]);
            }
            $exact = Decimal::round($exact, $to->decimal_precision);
        }

        return ['quantity' => $exact, 'factor' => $factor];
    }

    /**
     * @return list<string> validation errors for a universal conversion
     */
    public function validateUniversal(UnitOfMeasure $from, UnitOfMeasure $to, string $factor, ?int $ignoreId = null): array
    {
        $errors = [];

        if ($from->id === $to->id) {
            return ['A unit cannot be converted to itself.'];
        }
        if ($from->dimension !== $to->dimension) {
            $errors[] = "{$from->code} ({$from->dimension}) and {$to->code} ({$to->dimension}) measure different dimensions; a universal conversion is not possible.";
        } elseif (! $from->isConvertible()) {
            $errors[] = "{$from->code} and {$to->code} are {$from->dimension} units. Universal conversions are not allowed for this dimension — define an item-specific conversion instead.";
        }
        if (! Decimal::isPositive($factor)) {
            $errors[] = 'The conversion factor must be greater than zero.';
        }
        if ($errors) {
            return $errors;
        }

        $reverse = UomConversion::query()->where('from_uom_id', $to->id)->where('to_uom_id', $from->id)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($reverse) {
            return ["A conversion between {$to->code} and {$from->code} already exists in the reverse direction."];
        }

        $existing = $this->factor($from->id, $to->id, null, $ignoreId);
        if ($existing !== null && ! Decimal::approximatelyEqual($factor, $existing)) {
            $errors[] = "This contradicts existing conversions, which imply 1 {$from->code} = {$existing} {$to->code}.";
        }

        return $errors;
    }

    /**
     * @return list<string> validation errors for an item-specific conversion
     */
    public function validateItemConversion(Item $item, UnitOfMeasure $uom, string $factor, ?int $ignoreId = null): array
    {
        $base = $item->baseUom;

        if ($uom->id === $base->id) {
            return ['The alternate unit must differ from the item\'s base unit.'];
        }
        if (! Decimal::isPositive($factor)) {
            return ['The conversion factor must be greater than zero.'];
        }

        // Within one convertible dimension the universal factor already applies.
        $universal = $this->factor($uom->id, $base->id, null);
        if ($universal !== null) {
            return ["{$uom->code} already converts universally to {$base->code} (1 {$uom->code} = {$universal} {$base->code}); an item-specific factor is not needed."];
        }

        $existing = $this->factor($uom->id, $base->id, $item, null, $ignoreId);
        if ($existing !== null && ! Decimal::approximatelyEqual($factor, $existing)) {
            return ["This contradicts this item's other conversions, which imply 1 {$uom->code} = {$existing} {$base->code}."];
        }

        return [];
    }

    /** @return array<int, array<int, string>> adjacency list with factors */
    private function graph(?Item $item, ?int $ignoreUniversalId, ?int $ignoreItemConversionId): array
    {
        $graph = [];
        $add = function (int $a, int $b, string $factor) use (&$graph) {
            $graph[$a][$b] = $factor;
            $graph[$b][$a] = Decimal::div('1', $factor);
        };

        /** @var Collection<int, UomConversion> $universal */
        $universal = UomConversion::query()->where('is_active', true)
            ->when($ignoreUniversalId, fn ($q) => $q->whereKeyNot($ignoreUniversalId))
            ->get(['id', 'from_uom_id', 'to_uom_id', 'factor']);
        foreach ($universal as $c) {
            $add($c->from_uom_id, $c->to_uom_id, (string) $c->factor);
        }

        if ($item) {
            $itemConversions = ItemUomConversion::query()->where('item_id', $item->id)->where('is_active', true)
                ->when($ignoreItemConversionId, fn ($q) => $q->whereKeyNot($ignoreItemConversionId))
                ->get(['uom_id', 'factor']);
            foreach ($itemConversions as $c) {
                $add($c->uom_id, $item->base_uom_id, (string) $c->factor);
            }
        }

        return $graph;
    }
}
