<?php

namespace App\Services\MasterData;

use App\Models\MasterData\Bom;
use App\Models\MasterData\BomLine;
use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Services\ActivityLogger;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bill of materials: planned quantities entered by authorised users only.
 * Creating or approving a BOM never issues or consumes stock.
 */
class BomService extends DocumentLifecycle
{
    private const MAX_LEVELS = 25;

    public function __construct(ActivityLogger $activity, private readonly UomConverter $uoms)
    {
        parent::__construct($activity);
    }

    protected function entity(): string
    {
        return 'bom';
    }

    protected function modelClass(): string
    {
        return Bom::class;
    }

    protected function numberPrefix(): string
    {
        return 'BOM-';
    }

    protected function numberColumn(): string
    {
        return 'bom_number';
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function saveDraft(?Bom $bom, array $header, array $lines): Bom
    {
        if ($bom) {
            $this->ensureEditable($bom);
        }

        $item = Item::query()->with('baseUom')->findOrFail($header['item_id'] ?? $bom->item_id);
        $errors = $this->headerErrors($item, $header, $bom);
        $errors += $this->lineErrors($item, $lines);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($bom, $header, $lines, $item) {
            $isNew = ! $bom;
            $bom ??= new Bom(['item_id' => $item->id]);
            $before = $isNew ? [] : $bom->only(['output_qty', 'output_uom_id', 'description', 'effective_from', 'effective_to']);

            $bom->fill(collect($header)->only(['output_qty', 'output_uom_id', 'description', 'effective_from', 'effective_to'])->all());
            if ($isNew) {
                $bom->item_id = $item->id;
                $bom->bom_number = $this->newNumber();
                $bom->version = $this->nextVersion($item->id);
                $bom->status = Bom::STATUS_DRAFT;
            }
            $bom->save();

            $oldLines = $isNew ? [] : $this->lineSnapshot($bom);
            $bom->lines()->delete();
            foreach (array_values($lines) as $index => $line) {
                $bom->lines()->create([
                    'line_no' => $index + 1,
                    'component_item_id' => $line['component_item_id'],
                    'quantity' => Decimal::normalize($line['quantity']),
                    'uom_id' => $line['uom_id'],
                    'scrap_percent' => Decimal::normalize($line['scrap_percent'] ?? '0'),
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }

            $this->activity->log($isNew ? 'bom.created' : 'bom.updated', $bom, array_filter([
                'number' => $bom->bom_number,
                'version' => $bom->version,
                'changes' => $isNew ? null : $this->activity->diff($before, $bom->only(array_keys($before))),
                'lines' => $isNew ? count($lines) : ($oldLines !== $this->lineSnapshot($bom) ? ['from' => count($oldLines), 'to' => count($lines)] : null),
            ]), ($isNew ? 'Created ' : 'Updated ')."BOM {$bom->bom_number} for {$item->code}");

            return $bom->load('lines');
        });
    }

    /**
     * True when adding $componentId under $productId would make the product
     * (directly or indirectly) a component of itself. Considers every
     * non-inactive BOM version, drafts included.
     */
    public function createsCycle(int $productId, int $componentId): bool
    {
        if ($productId === $componentId) {
            return true;
        }

        $visited = [];
        $stack = [$componentId];
        while ($stack) {
            $current = array_pop($stack);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $children = BomLine::query()
                ->whereHas('bom', fn ($q) => $q->where('item_id', $current)->where('status', '!=', Bom::STATUS_INACTIVE))
                ->pluck('component_item_id');

            foreach ($children as $child) {
                if ($child === $productId) {
                    return true;
                }
                $stack[] = $child;
            }
        }

        return false;
    }

    /**
     * Multi-level structure using each component's approved BOM effective on
     * $date. Quantities are per the BOM's output quantity, in component base
     * UOM, including expected scrap.
     *
     * @return array<string, mixed>
     */
    public function structure(Bom $bom, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $bom->loadMissing(['item.baseUom', 'outputUom', 'lines.component.baseUom', 'lines.uom']);

        return [
            'bom_id' => $bom->id,
            'bom_number' => $bom->bom_number,
            'version' => $bom->version,
            'item' => ['id' => $bom->item->id, 'code' => $bom->item->code, 'name' => $bom->item->name],
            'output_qty' => Decimal::normalize((string) $bom->output_qty),
            'output_uom' => $bom->outputUom->code,
            'components' => $this->explode($bom, '1', $date, [$bom->item_id], 1),
        ];
    }

    /** @return list<array<int, string>> flattened rows for export */
    public function exportRows(Bom $bom): array
    {
        $structure = $this->structure($bom);
        $rows = [];
        $walk = function (array $components) use (&$walk, &$rows) {
            foreach ($components as $c) {
                $rows[] = [
                    (string) $c['level'], $c['item']['code'], $c['item']['name'], $c['quantity'], $c['uom'], $c['scrap_percent'],
                    $c['base_quantity_with_scrap'] ?? '', $c['base_uom'], $c['sub_bom'] ?? '', $c['remarks'] ?? '',
                ];
                $walk($c['children']);
            }
        };
        $walk($structure['components']);

        return $rows;
    }

    protected function approvalErrors(Model $document): array
    {
        /** @var Bom $document */
        $document->load(['item.baseUom', 'lines']);
        $errors = [];

        if ($document->lines->isEmpty()) {
            $errors[] = 'Add at least one component before approval.';
        }
        $header = $document->only(['output_qty', 'output_uom_id', 'effective_from', 'effective_to']);
        $header['effective_from'] = $document->effective_from->toDateString();
        $header['effective_to'] = $document->effective_to?->toDateString();
        $lines = $document->lines->map(fn (BomLine $l) => $l->only(['component_item_id', 'quantity', 'uom_id', 'scrap_percent']))->all();

        foreach ($this->headerErrors($document->item, $header, $document) + $this->lineErrors($document->item, $lines) as $messages) {
            array_push($errors, ...(array) $messages);
        }

        return $errors;
    }

    protected function copyLines(Model $source, Model $copy): void
    {
        foreach ($source->lines as $line) {
            $copy->lines()->create($line->only(['line_no', 'component_item_id', 'quantity', 'uom_id', 'scrap_percent', 'remarks']));
        }
    }

    /** @return array<string, string> */
    private function headerErrors(Item $item, array $header, ?Bom $bom): array
    {
        $errors = [];

        if (! $item->canHaveBom()) {
            $errors['item_id'] = "{$item->code} is not a manufactured or semi-finished/finished item, so it cannot have a BOM.";
        } elseif (! $item->is_active && (! $bom || $bom->item_id !== $item->id)) {
            $errors['item_id'] = "{$item->code} is inactive.";
        }

        if (isset($header['output_uom_id']) && ! $this->uoms->canConvert((int) $header['output_uom_id'], $item->base_uom_id, $item)) {
            $errors['output_uom_id'] = "The output unit cannot be converted to {$item->code}'s base unit ({$item->baseUom->code}). Add an item-specific conversion first.";
        }
        if (isset($header['output_qty']) && ! Decimal::isPositive((string) $header['output_qty'])) {
            $errors['output_qty'] = 'Output quantity must be greater than zero.';
        } elseif (isset($header['output_qty'], $header['output_uom_id'])) {
            $uom = UnitOfMeasure::find($header['output_uom_id']);
            if ($uom && Decimal::decimals($header['output_qty']) > $uom->decimal_precision) {
                $errors['output_qty'] = "{$uom->code} allows at most {$uom->decimal_precision} decimal places.";
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function lineErrors(Item $product, array $lines): array
    {
        $errors = [];
        $components = Item::query()->with('baseUom')->whereKey(collect($lines)->pluck('component_item_id')->filter())->get()->keyBy('id');
        $uoms = UnitOfMeasure::query()->whereKey(collect($lines)->pluck('uom_id')->filter())->get()->keyBy('id');
        $seen = [];

        foreach (array_values($lines) as $i => $line) {
            $component = $components->get($line['component_item_id'] ?? 0);
            $uom = $uoms->get($line['uom_id'] ?? 0);
            if (! $component || ! $uom) {
                $errors["lines.{$i}.component_item_id"] = 'Select a valid component and unit.';

                continue;
            }
            if (isset($seen[$component->id])) {
                $errors["lines.{$i}.component_item_id"] = "{$component->code} is listed more than once.";
            }
            $seen[$component->id] = true;

            if (! $component->is_active) {
                $errors["lines.{$i}.component_item_id"] = "{$component->code} is inactive.";
            } elseif ($this->createsCycle($product->id, $component->id)) {
                $errors["lines.{$i}.component_item_id"] = "Adding {$component->code} would create a circular BOM ({$product->code} would contain itself).";
            }

            if (! $this->uoms->canConvert($uom->id, $component->base_uom_id, $component)) {
                $errors["lines.{$i}.uom_id"] = "{$uom->code} cannot be converted to {$component->code}'s base unit ({$component->baseUom->code}). Define a universal or item-specific conversion.";
            }

            $qty = (string) ($line['quantity'] ?? '');
            if (! Decimal::isPositive($qty)) {
                $errors["lines.{$i}.quantity"] = 'Quantity must be greater than zero.';
            } elseif (Decimal::decimals($qty) > $uom->decimal_precision) {
                $errors["lines.{$i}.quantity"] = "{$uom->code} allows at most {$uom->decimal_precision} decimal places.";
            }

            $scrap = (string) ($line['scrap_percent'] ?? '0');
            if (! Decimal::isNumeric($scrap) || Decimal::cmp($scrap, '0') < 0 || Decimal::cmp($scrap, '100') >= 0) {
                $errors["lines.{$i}.scrap_percent"] = 'Scrap % must be between 0 and 99.999.';
            }
        }

        return $errors;
    }

    /** @return list<array<string, mixed>> */
    private function explode(Bom $bom, string $multiplier, string $date, array $path, int $level): array
    {
        if ($level > self::MAX_LEVELS) {
            return [];
        }

        return $bom->lines->map(function (BomLine $line) use ($multiplier, $date, $path, $level) {
            $component = $line->component;
            $qty = Decimal::mul((string) $line->quantity, $multiplier);
            $withScrap = Decimal::mul($qty, Decimal::div(bcadd('100', Decimal::normalize((string) $line->scrap_percent), 6), '100'));
            $factor = $this->uoms->factor($line->uom_id, $component->base_uom_id, $component);
            $baseQty = $factor !== null ? Decimal::mul($withScrap, $factor) : null;

            $children = [];
            $subBom = null;
            if (! in_array($component->id, $path, true)) {
                $subBom = Bom::query()->where('item_id', $component->id)->effectiveOn($date)
                    ->with(['lines.component.baseUom', 'lines.uom'])->first();
                if ($subBom && $baseQty !== null) {
                    $outputFactor = $this->uoms->factor($subBom->output_uom_id, $component->base_uom_id, $component);
                    if ($outputFactor !== null) {
                        // Per one unit of sub-BOM output: baseQty / (output_qty × outputFactor)
                        $perOutput = Decimal::div($baseQty, Decimal::mul((string) $subBom->output_qty, $outputFactor));
                        $children = $this->explode($subBom, $perOutput, $date, [...$path, $component->id], $level + 1);
                    }
                }
            }

            return [
                'level' => $level,
                'line_no' => $line->line_no,
                'item' => ['id' => $component->id, 'code' => $component->code, 'name' => $component->name, 'item_type' => $component->item_type],
                'quantity' => $qty,
                'uom' => $line->uom->code,
                'scrap_percent' => Decimal::normalize((string) $line->scrap_percent),
                'base_quantity_with_scrap' => $baseQty,
                'base_uom' => $component->baseUom->code,
                'remarks' => $line->remarks,
                'sub_bom' => $subBom ? "{$subBom->bom_number} v{$subBom->version}" : null,
                'children' => $children,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function lineSnapshot(Bom $bom): array
    {
        return $bom->lines()->get(['component_item_id', 'quantity', 'uom_id', 'scrap_percent', 'remarks'])->toArray();
    }

    public function linesCollection(Bom $bom): Collection
    {
        return $bom->lines()->with(['component.baseUom', 'uom'])->get();
    }
}
