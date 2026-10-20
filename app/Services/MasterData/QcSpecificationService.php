<?php

namespace App\Services\MasterData;

use App\Models\MasterData\Item;
use App\Models\MasterData\ManufacturingProcess;
use App\Models\MasterData\QcParameter;
use App\Models\MasterData\QcSpecification;
use App\Models\MasterData\QcSpecificationLine;
use App\Models\MasterData\UnitOfMeasure;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QC specifications (what to inspect and the acceptance limits). Actual
 * inspection entries belong to the Quality Control module.
 */
class QcSpecificationService extends DocumentLifecycle
{
    protected function entity(): string
    {
        return 'qc-specification';
    }

    protected function versionColumn(): string
    {
        return 'revision';
    }

    protected function modelClass(): string
    {
        return QcSpecification::class;
    }

    protected function numberPrefix(): string
    {
        return 'QCS-';
    }

    protected function numberColumn(): string
    {
        return 'spec_number';
    }

    public function saveDraft(?QcSpecification $spec, array $header, array $lines): QcSpecification
    {
        if ($spec) {
            $this->ensureEditable($spec);
        }
        $item = Item::query()->findOrFail($header['item_id'] ?? $spec->item_id);

        $errors = [];
        if (! $item->is_active && (! $spec || $spec->item_id !== $item->id)) {
            $errors['item_id'] = "{$item->code} is inactive.";
        }
        $errors += $this->lineErrors($lines);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($spec, $header, $lines, $item) {
            $isNew = ! $spec;
            $spec ??= new QcSpecification(['item_id' => $item->id]);
            $before = $isNew ? [] : $spec->only(['description', 'inspection_instructions', 'effective_from', 'effective_to']);

            $spec->fill(collect($header)->only(['description', 'inspection_instructions', 'effective_from', 'effective_to'])->all());
            if ($isNew) {
                $spec->item_id = $item->id;
                $spec->spec_number = $this->newNumber();
                $spec->revision = $this->nextVersion($item->id);
                $spec->status = QcSpecification::STATUS_DRAFT;
            }
            $spec->save();

            $spec->lines()->delete();
            $parameters = QcParameter::query()->whereKey(collect($lines)->pluck('parameter_id'))->get()->keyBy('id');
            foreach (array_values($lines) as $i => $line) {
                $parameter = $parameters->get($line['parameter_id']);
                $numeric = $parameter->data_type === QcParameter::NUMERIC;
                $spec->lines()->create([
                    'line_no' => $i + 1,
                    'parameter_id' => $parameter->id,
                    'process_id' => $line['process_id'] ?? null,
                    'target_value' => $numeric ? $this->decimalOrNull($line['target_value'] ?? null) : null,
                    'min_value' => $numeric ? $this->decimalOrNull($line['min_value'] ?? null) : null,
                    'max_value' => $numeric ? $this->decimalOrNull($line['max_value'] ?? null) : null,
                    'uom_id' => $numeric ? ($line['uom_id'] ?? $parameter->default_uom_id) : null,
                    'expected_text' => $numeric ? null : ($line['expected_text'] ?? null),
                    'allowed_options' => $parameter->data_type === QcParameter::SELECTION ? array_values($line['allowed_options'] ?? []) : null,
                    'inspection_method' => $line['inspection_method'] ?? null,
                    'instructions' => $line['instructions'] ?? null,
                    'is_required' => (bool) ($line['is_required'] ?? true),
                ]);
            }

            $this->activity->log($isNew ? 'qc-specification.created' : 'qc-specification.updated', $spec, array_filter([
                'number' => $spec->spec_number,
                'revision' => $spec->revision,
                'changes' => $isNew ? null : $this->activity->diff($before, $spec->only(array_keys($before))),
                'lines' => count($lines),
            ]), ($isNew ? 'Created ' : 'Updated ')."QC specification {$spec->spec_number} for {$item->code}");

            return $spec->load('lines');
        });
    }

    protected function approvalErrors(Model $document): array
    {
        /** @var QcSpecification $document */
        $document->load('lines');
        $errors = [];
        if ($document->lines->isEmpty()) {
            $errors[] = 'Add at least one parameter before approval.';
        }
        $lines = $document->lines->map(fn (QcSpecificationLine $l) => [
            ...$l->only(['parameter_id', 'process_id', 'uom_id', 'expected_text', 'allowed_options']),
            'target_value' => $l->target_value, 'min_value' => $l->min_value, 'max_value' => $l->max_value,
        ])->all();
        foreach ($this->lineErrors($lines) as $message) {
            $errors[] = $message;
        }

        return $errors;
    }

    protected function copyLines(Model $source, Model $copy): void
    {
        foreach ($source->lines as $line) {
            $copy->lines()->create($line->only([
                'line_no', 'parameter_id', 'process_id', 'target_value', 'min_value', 'max_value', 'uom_id',
                'expected_text', 'allowed_options', 'inspection_method', 'instructions', 'is_required',
            ]));
        }
    }

    /**
     * Data-type aware validation. Numeric limits are compared exactly
     * (bcmath) and must be in a unit of the parameter's dimension.
     *
     * @return array<string, string>
     */
    public function lineErrors(array $lines): array
    {
        $errors = [];
        $parameters = QcParameter::query()->with('defaultUom')->whereKey(collect($lines)->pluck('parameter_id')->filter())->get()->keyBy('id');
        $processes = ManufacturingProcess::query()->whereKey(collect($lines)->pluck('process_id')->filter())->get()->keyBy('id');
        $uoms = UnitOfMeasure::query()->whereKey(collect($lines)->pluck('uom_id')->filter())->get()->keyBy('id');
        $seen = [];

        foreach (array_values($lines) as $i => $line) {
            $p = "lines.{$i}";
            $parameter = $parameters->get($line['parameter_id'] ?? 0);
            if (! $parameter) {
                $errors["{$p}.parameter_id"] = 'Select a valid parameter.';

                continue;
            }
            if (! $parameter->is_active) {
                $errors["{$p}.parameter_id"] = "Parameter {$parameter->name} is inactive.";
            }

            $processId = $line['process_id'] ?? null;
            if ($processId && ! $processes->get($processId)?->is_active) {
                $errors["{$p}.process_id"] = 'The process is invalid or inactive.';
            }
            $key = $parameter->id.'|'.($processId ?? '');
            if (isset($seen[$key])) {
                $errors["{$p}.parameter_id"] = "{$parameter->name} is listed more than once for the same process.";
            }
            $seen[$key] = true;

            $target = $this->blankToNull($line['target_value'] ?? null);
            $min = $this->blankToNull($line['min_value'] ?? null);
            $max = $this->blankToNull($line['max_value'] ?? null);

            switch ($parameter->data_type) {
                case QcParameter::NUMERIC:
                    foreach (['target_value' => $target, 'min_value' => $min, 'max_value' => $max] as $field => $value) {
                        if ($value !== null && (! Decimal::isNumeric($value) || Decimal::decimals($value) > 6)) {
                            $errors["{$p}.{$field}"] = 'Enter a number with at most 6 decimal places.';
                        }
                    }
                    if ($target === null && $min === null && $max === null) {
                        $errors["{$p}.target_value"] = 'Numeric parameters need a target, minimum or maximum value.';
                    }
                    if (isset($errors["{$p}.min_value"]) || isset($errors["{$p}.max_value"]) || isset($errors["{$p}.target_value"])) {
                        break;
                    }
                    if ($min !== null && $max !== null && Decimal::cmp($min, $max) > 0) {
                        $errors["{$p}.min_value"] = 'The minimum cannot be greater than the maximum.';
                    }
                    if ($target !== null && $min !== null && Decimal::cmp($target, $min) < 0) {
                        $errors["{$p}.target_value"] = 'The target is below the minimum.';
                    }
                    if ($target !== null && $max !== null && Decimal::cmp($target, $max) > 0) {
                        $errors["{$p}.target_value"] = 'The target is above the maximum.';
                    }

                    $uomId = $line['uom_id'] ?? $parameter->default_uom_id;
                    $uom = $uomId ? ($uoms->get($uomId) ?? UnitOfMeasure::find($uomId)) : null;
                    if (! $uom) {
                        $errors["{$p}.uom_id"] = 'Numeric parameters need a measurement unit.';
                    } elseif ($parameter->defaultUom && $uom->dimension !== $parameter->defaultUom->dimension) {
                        $errors["{$p}.uom_id"] = "{$parameter->name} is measured in {$parameter->defaultUom->dimension} units; {$uom->code} is a {$uom->dimension} unit.";
                    }
                    break;

                case QcParameter::SELECTION:
                    $allowed = array_values(array_filter((array) ($line['allowed_options'] ?? []), fn ($v) => $v !== null && $v !== ''));
                    $invalid = array_diff($allowed, (array) $parameter->options);
                    if (! $allowed) {
                        $errors["{$p}.allowed_options"] = 'Choose at least one acceptable option.';
                    } elseif ($invalid) {
                        $errors["{$p}.allowed_options"] = 'Unknown options: '.implode(', ', $invalid);
                    }
                    // no break: selection, pass/fail and text carry no numeric limits
                default:
                    if ($target !== null || $min !== null || $max !== null) {
                        $errors["{$p}.min_value"] = "{$parameter->name} is a ".config("erp.master_data.qc_data_types.{$parameter->data_type}").' parameter and cannot have numeric limits.';
                    }
            }
        }

        return $errors;
    }

    private function blankToNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        $value = $this->blankToNull($value);

        return $value === null ? null : Decimal::normalize($value);
    }
}
