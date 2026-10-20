<?php

namespace App\Services\MasterData;

use App\Models\Department;
use App\Models\MasterData\Item;
use App\Models\MasterData\Machine;
use App\Models\MasterData\ManufacturingProcess;
use App\Models\MasterData\ProductRouting;
use App\Models\MasterData\RoutingOperation;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product routing (operation sequence). Defines standards only — no
 * production jobs are created here.
 */
class RoutingService extends DocumentLifecycle
{
    protected function entity(): string
    {
        return 'routing';
    }

    protected function modelClass(): string
    {
        return ProductRouting::class;
    }

    protected function numberPrefix(): string
    {
        return 'RTG-';
    }

    protected function numberColumn(): string
    {
        return 'routing_number';
    }

    /**
     * Operations are stored in the order of their sequence numbers. When an
     * operation omits "sequence", its position in the list is used.
     *
     * @param  list<array<string, mixed>>  $operations
     */
    public function saveDraft(?ProductRouting $routing, array $header, array $operations): ProductRouting
    {
        if ($routing) {
            $this->ensureEditable($routing);
        }
        $item = Item::query()->findOrFail($header['item_id'] ?? $routing->item_id);

        $operations = $this->normalizeSequence($operations);
        $errors = $this->headerErrors($item, $routing) + $this->operationErrors($operations);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($routing, $header, $operations, $item) {
            $isNew = ! $routing;
            $routing ??= new ProductRouting(['item_id' => $item->id]);
            $before = $isNew ? [] : $routing->only(['description', 'effective_from', 'effective_to']);

            $routing->fill(collect($header)->only(['description', 'effective_from', 'effective_to'])->all());
            if ($isNew) {
                $routing->item_id = $item->id;
                $routing->routing_number = $this->newNumber();
                $routing->version = $this->nextVersion($item->id);
                $routing->status = ProductRouting::STATUS_DRAFT;
            }
            $routing->save();

            $previous = $isNew ? [] : $routing->operations()->pluck('process_id', 'sequence')->all();
            $routing->operations()->delete();
            $processes = ManufacturingProcess::query()->whereKey(collect($operations)->pluck('process_id'))->get()->keyBy('id');
            foreach ($operations as $op) {
                $process = $processes->get($op['process_id']);
                $routing->operations()->create([
                    'sequence' => $op['sequence'],
                    'process_id' => $op['process_id'],
                    'department_id' => $op['department_id'] ?? $process?->default_department_id,
                    'machine_id' => $op['machine_id'] ?? null,
                    'setup_time' => Decimal::normalize($op['setup_time'] ?? '0'),
                    'cycle_time' => Decimal::normalize($op['cycle_time'] ?? '0'),
                    'time_unit' => $op['time_unit'] ?? 'minute',
                    'process_loss_percent' => Decimal::normalize($op['process_loss_percent'] ?? '0'),
                    'requires_qc' => (bool) ($op['requires_qc'] ?? $process?->requires_qc ?? false),
                    'remarks' => $op['remarks'] ?? null,
                ]);
            }

            $current = $routing->operations()->pluck('process_id', 'sequence')->all();
            $this->activity->log($isNew ? 'routing.created' : 'routing.updated', $routing, array_filter([
                'number' => $routing->routing_number,
                'version' => $routing->version,
                'changes' => $isNew ? null : $this->activity->diff($before, $routing->only(array_keys($before))),
                'operations' => $isNew || $previous !== $current ? ['from' => $previous, 'to' => $current] : null,
            ]), ($isNew ? 'Created ' : 'Updated ')."routing {$routing->routing_number} for {$item->code}");

            return $routing->load('operations');
        });
    }

    protected function approvalErrors(Model $document): array
    {
        /** @var ProductRouting $document */
        $document->load(['item', 'operations']);
        $errors = [];
        if ($document->operations->isEmpty()) {
            $errors[] = 'Add at least one operation before approval.';
        }
        $ops = $document->operations->map(fn (RoutingOperation $o) => $o->only([
            'sequence', 'process_id', 'department_id', 'machine_id', 'setup_time', 'cycle_time', 'time_unit', 'process_loss_percent',
        ]))->all();
        foreach ($this->headerErrors($document->item, $document) + $this->operationErrors($ops) as $message) {
            $errors[] = $message;
        }

        return $errors;
    }

    protected function copyLines(Model $source, Model $copy): void
    {
        foreach ($source->operations as $op) {
            $copy->operations()->create($op->only([
                'sequence', 'process_id', 'department_id', 'machine_id', 'setup_time', 'cycle_time', 'time_unit',
                'process_loss_percent', 'requires_qc', 'remarks',
            ]));
        }
    }

    /** @return list<array<string, mixed>> sorted by sequence */
    private function normalizeSequence(array $operations): array
    {
        $operations = array_values($operations);
        foreach ($operations as $i => &$op) {
            $op['sequence'] = isset($op['sequence']) && $op['sequence'] !== '' && $op['sequence'] !== null ? (int) $op['sequence'] : $i + 1;
        }
        unset($op);

        usort($operations, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        return $operations;
    }

    /** @return array<string, string> */
    private function headerErrors(Item $item, ?ProductRouting $routing): array
    {
        if (! $item->canHaveBom()) {
            return ['item_id' => "{$item->code} is not a manufactured item, so it cannot have a routing."];
        }
        if (! $item->is_active && (! $routing || $routing->item_id !== $item->id)) {
            return ['item_id' => "{$item->code} is inactive."];
        }

        return [];
    }

    /**
     * Validates processes, departments, machines and machine/process
     * compatibility. Keys refer to positions after sorting by sequence.
     *
     * @return array<string, string>
     */
    private function operationErrors(array $operations): array
    {
        $errors = [];
        $processes = ManufacturingProcess::query()->whereKey(collect($operations)->pluck('process_id')->filter())->get()->keyBy('id');
        $machines = Machine::query()->with('processes:id')->whereKey(collect($operations)->pluck('machine_id')->filter())->get()->keyBy('id');
        $departments = Department::query()->whereKey(collect($operations)->pluck('department_id')->filter()
            ->merge($processes->pluck('default_department_id')->filter()))->get()->keyBy('id');
        $sequences = [];

        foreach ($operations as $i => $op) {
            $seq = $op['sequence'];
            if ($seq < 1) {
                $errors["operations.{$i}.sequence"] = 'Sequence numbers must be positive.';
            } elseif (isset($sequences[$seq])) {
                $errors["operations.{$i}.sequence"] = "Sequence {$seq} is used more than once.";
            }
            $sequences[$seq] = true;

            $process = $processes->get($op['process_id'] ?? 0);
            if (! $process) {
                $errors["operations.{$i}.process_id"] = 'Select a valid process.';

                continue;
            }
            if (! $process->is_active) {
                $errors["operations.{$i}.process_id"] = "Process {$process->name} is inactive.";
            }

            $departmentId = $op['department_id'] ?? $process->default_department_id;
            $department = $departmentId ? $departments->get($departmentId) : null;
            if ($departmentId && (! $department || ! $department->is_active)) {
                $errors["operations.{$i}.department_id"] = 'The department is invalid or inactive.';
            }

            if (! empty($op['machine_id'])) {
                $machine = $machines->get($op['machine_id']);
                if (! $machine || ! $machine->is_active) {
                    $errors["operations.{$i}.machine_id"] = 'The machine is invalid or inactive.';
                } elseif (! $machine->processes->contains('id', $process->id)) {
                    $errors["operations.{$i}.machine_id"] = "{$machine->code} is not configured for process {$process->name}.";
                } elseif ($departmentId && $machine->department_id !== (int) $departmentId) {
                    $errors["operations.{$i}.machine_id"] = "{$machine->code} belongs to a different department.";
                }
            }

            foreach (['setup_time', 'cycle_time'] as $field) {
                $value = (string) ($op[$field] ?? '0');
                if (! Decimal::isNumeric($value) || Decimal::cmp($value, '0') < 0) {
                    $errors["operations.{$i}.{$field}"] = 'Times must be zero or more.';
                }
            }
            $loss = (string) ($op['process_loss_percent'] ?? '0');
            if (! Decimal::isNumeric($loss) || Decimal::cmp($loss, '0') < 0 || Decimal::cmp($loss, '100') >= 0) {
                $errors["operations.{$i}.process_loss_percent"] = 'Process loss % must be between 0 and 99.999.';
            }
            if (! array_key_exists($op['time_unit'] ?? 'minute', config('erp.master_data.time_units'))) {
                $errors["operations.{$i}.time_unit"] = 'Invalid time unit.';
            }
        }

        return $errors;
    }
}
