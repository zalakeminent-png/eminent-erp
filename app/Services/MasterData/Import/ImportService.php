<?php

namespace App\Services\MasterData\Import;

use App\Jobs\ProcessImportBatch;
use App\Models\MasterData\ImportBatch;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Spreadsheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Excel/CSV import workflow:
 *   upload → map columns → validate & preview → confirm → commit.
 * Existing records are only updated when the user explicitly enables
 * updates AND confirms them. Every row is re-validated at commit time and
 * invalid rows are skipped, never partially written.
 */
class ImportService
{
    private const DISK = 'local';

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @return array<string, Importer> */
    public function importers(): array
    {
        return [
            'item-categories' => new ItemCategoryImporter,
            'items' => new ItemImporter,
            'material-grades' => new MaterialGradeImporter,
            'customers' => new BusinessPartnerImporter('customer'),
            'suppliers' => new BusinessPartnerImporter('supplier'),
        ];
    }

    public function importer(string $entity): Importer
    {
        return $this->importers()[$entity] ?? throw ValidationException::withMessages(['entity' => 'Unknown import type.']);
    }

    public function upload(string $entity, UploadedFile $file, User $actor): ImportBatch
    {
        $importer = $this->importer($entity);
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('imports', Str::random(40).".{$extension}", self::DISK);

        try {
            $headers = [];
            $count = 0;
            foreach (Spreadsheet::rows(Storage::disk(self::DISK)->path($path), $extension) as $number => $row) {
                if ($number === 1) {
                    $headers = array_values(array_filter($row, fn ($h) => $h !== ''));
                } elseif (array_filter($row, fn ($v) => $v !== '')) {
                    $count++;
                }
            }
        } catch (Throwable) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['file' => 'The file could not be read. Upload a valid .xlsx or .csv file.']);
        }

        $max = (int) config('erp.master_data.import.max_rows');
        $problem = match (true) {
            ! $headers => 'The first row must contain column headings.',
            count($headers) !== count(array_unique(array_map('strtolower', $headers))) => 'Column headings must be unique.',
            $count === 0 => 'The file contains no data rows.',
            $count > $max => "The file has {$count} rows; the maximum per import is {$max}.",
            default => null,
        };
        if ($problem) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['file' => $problem]);
        }

        $batch = new ImportBatch([
            'entity' => $entity,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'path' => $path,
            'status' => ImportBatch::UPLOADED,
            'headers' => $headers,
            'mapping' => $this->suggestMapping($importer, $headers),
            'created_by' => $actor->id,
        ]);
        $batch->total_rows = $count;
        $batch->save();

        $this->activity->log('import.uploaded', $batch, ['entity' => $entity, 'file' => $batch->original_name, 'rows' => $count], "Uploaded {$importer->label()} import file");

        return $batch;
    }

    /**
     * Validates every row with the given mapping and stores per-row results.
     *
     * @param  array<string, string|null>  $mapping  column key => source heading
     */
    public function validate(ImportBatch $batch, array $mapping, bool $updateExisting): ImportBatch
    {
        $this->ensureStatus($batch, [ImportBatch::UPLOADED, ImportBatch::VALIDATED]);
        $importer = $this->importer($batch->entity);
        $mapping = $this->checkMapping($importer, $batch, $mapping);

        $results = $this->evaluate($batch, $importer, $mapping, $updateExisting);

        $batch->fill(['mapping' => $mapping, 'update_existing' => $updateExisting, 'status' => ImportBatch::VALIDATED]);
        $batch->forceFill([
            'row_results' => $results,
            'valid_rows' => collect($results)->whereIn('action', ['create', 'update'])->count(),
            'error_rows' => collect($results)->where('action', 'error')->count(),
            'create_count' => collect($results)->where('action', 'create')->count(),
            'update_count' => collect($results)->where('action', 'update')->count(),
            'validated_at' => now(),
        ])->save();

        return $batch;
    }

    /**
     * Imports the valid rows. Updates require $confirmUpdates. Small files
     * run immediately in a single transaction; large ones are queued.
     */
    public function commit(ImportBatch $batch, bool $confirmUpdates): ImportBatch
    {
        $this->ensureStatus($batch, [ImportBatch::VALIDATED]);
        if ($batch->update_count > 0 && ! $confirmUpdates) {
            throw ValidationException::withMessages(['confirm_updates' => "{$batch->update_count} existing record(s) would be updated. Confirm the updates to continue."]);
        }
        if ($batch->valid_rows === 0) {
            throw ValidationException::withMessages(['batch' => 'There are no valid rows to import.']);
        }

        $batch->forceFill(['status' => ImportBatch::PROCESSING])->save();

        if ($batch->total_rows > (int) config('erp.master_data.import.queue_threshold')) {
            ProcessImportBatch::dispatch($batch->id);
        } else {
            $this->run($batch);
        }

        return $batch->refresh();
    }

    /** Executes the import (called inline or from the queue job). */
    public function run(ImportBatch $batch): void
    {
        $importer = $this->importer($batch->entity);
        $queued = $batch->total_rows > (int) config('erp.master_data.import.queue_threshold');

        try {
            $work = function () use ($batch, $importer) {
                $results = $this->evaluate($batch, $importer, $batch->mapping, $batch->update_existing, persist: true);
                $batch->forceFill([
                    'row_results' => $results,
                    'created_count' => collect($results)->where('action', 'created')->count(),
                    'updated_count' => collect($results)->where('action', 'updated')->count(),
                    'skipped_count' => collect($results)->where('action', 'error')->count(),
                    'error_rows' => collect($results)->where('action', 'error')->count(),
                    'status' => ImportBatch::COMPLETED,
                    'completed_at' => now(),
                ])->save();
            };
            // Small imports are all-or-nothing; queued ones commit row by row.
            $queued ? $work() : DB::transaction($work);
        } catch (Throwable $e) {
            report($e);
            $batch->forceFill(['status' => ImportBatch::FAILED, 'failure_message' => 'The import stopped unexpectedly. No partial changes were kept for small imports; see the log for details.'])->save();

            return;
        }

        $this->activity->log('import.completed', $batch, [
            'entity' => $batch->entity, 'file' => $batch->original_name,
            'created' => $batch->created_count, 'updated' => $batch->updated_count, 'skipped' => $batch->skipped_count,
        ], "Imported {$importer->label()} from {$batch->original_name}", $batch->creator);
    }

    public function template(string $entity): BinaryFileResponse
    {
        $importer = $this->importer($entity);
        $columns = $importer->columns();

        return Spreadsheet::download(
            "{$entity}-import-template",
            array_map(fn ($c) => $c['label'].(! empty($c['required']) ? ' *' : ''), array_values($columns)),
            [array_map(fn ($c) => $c['example'] ?? '', array_values($columns))],
        );
    }

    public function errorReport(ImportBatch $batch): BinaryFileResponse
    {
        $errors = collect($batch->row_results ?? [])->where('action', 'error')->keyBy('row');
        $headers = $batch->headers ?? [];

        $rows = (function () use ($batch, $errors, $headers) {
            foreach (Spreadsheet::rows(Storage::disk(self::DISK)->path($batch->path), pathinfo($batch->path, PATHINFO_EXTENSION)) as $number => $row) {
                if ($number === 1 || ! $errors->has($number)) {
                    continue;
                }
                yield [$number, implode(' | ', $errors[$number]['errors']), ...array_slice(array_pad($row, count($headers), ''), 0, count($headers))];
            }
        })();

        return Spreadsheet::download("import-{$batch->id}-errors", ['Row', 'Errors', ...$headers], $rows, 'csv');
    }

    /**
     * Shared row loop for validation (dry run) and commit.
     *
     * @return list<array{row: int, key: ?string, action: string, errors: list<string>, values?: array<string, string>}>
     */
    private function evaluate(ImportBatch $batch, Importer $importer, array $mapping, bool $updateExisting, bool $persist = false): array
    {
        $headers = array_flip(array_map('strtolower', $batch->headers ?? []));
        $results = [];
        $validKeys = [];
        $keyLabel = $importer->keyColumn() ? $importer->columns()[$importer->keyColumn()]['label'] : 'key';

        // Pass 1: find keys that occur more than once — every occurrence is
        // rejected, identically in the dry run and the commit.
        $rowsByKey = [];
        foreach ($this->mappedRows($batch, $headers, $mapping) as $number => $row) {
            if (($key = $importer->keyOf($row)) !== null) {
                $rowsByKey[$key][] = $number;
            }
        }

        foreach ($this->mappedRows($batch, $headers, $mapping) as $number => $row) {
            $key = $importer->keyOf($row);
            $result = ['row' => $number, 'key' => $key, 'action' => 'error', 'errors' => []];
            if (count($results) < 100) {
                $result['values'] = array_filter($row, fn ($v) => $v !== '');
            }

            if ($key !== null && count($rowsByKey[$key]) > 1) {
                $others = implode(', ', array_diff($rowsByKey[$key], [$number]));
                $result['errors'][] = "Duplicate {$keyLabel} {$key} in the file (also on row {$others}).";
                $results[] = $result;

                continue;
            }

            $existing = $importer->findExisting($row);
            if ($existing && ! $updateExisting) {
                $result['errors'][] = "{$key} already exists. Enable \"Update existing records\" to change it.";
                $results[] = $result;

                continue;
            }

            $validation = $importer->validate($row, $existing, $validKeys);
            if ($validation['errors']) {
                $result['errors'] = $validation['errors'];
                $results[] = $result;

                continue;
            }

            if ($persist) {
                try {
                    DB::transaction(fn () => $importer->persist($validation['data'], $existing));
                    $result['action'] = $existing ? 'updated' : 'created';
                } catch (ValidationException $e) {
                    $result['errors'] = array_values(array_merge(...array_values($e->errors())));
                }
            } else {
                $result['action'] = $existing ? 'update' : 'create';
            }
            if ($key !== null && $result['action'] !== 'error') {
                $validKeys[$key] = true;
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  array<string, int>  $headers  lower-cased heading => index
     * @return \Generator<int, array<string, string>> row number => column key => cell text
     */
    private function mappedRows(ImportBatch $batch, array $headers, array $mapping): \Generator
    {
        foreach (Spreadsheet::rows(Storage::disk(self::DISK)->path($batch->path), pathinfo($batch->path, PATHINFO_EXTENSION)) as $number => $cells) {
            if ($number === 1 || ! array_filter($cells, fn ($v) => $v !== '')) {
                continue;
            }
            $row = [];
            foreach ($mapping as $column => $heading) {
                $index = $heading !== null ? ($headers[strtolower($heading)] ?? null) : null;
                $row[$column] = $index !== null ? (string) ($cells[$index] ?? '') : '';
            }
            yield $number => $row;
        }
    }

    /** @return array<string, string|null> */
    private function suggestMapping(Importer $importer, array $headers): array
    {
        $normalize = fn (string $v) => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('*', '', $v)));
        $byNormalized = [];
        foreach ($headers as $header) {
            $byNormalized[$normalize($header)] = $header;
        }

        $mapping = [];
        foreach ($importer->columns() as $key => $column) {
            $mapping[$key] = null;
            foreach ([$column['label'], $key, ...($column['aliases'] ?? [])] as $candidate) {
                if (isset($byNormalized[$normalize($candidate)])) {
                    $mapping[$key] = $byNormalized[$normalize($candidate)];
                    break;
                }
            }
        }

        return $mapping;
    }

    /** @return array<string, string|null> */
    private function checkMapping(Importer $importer, ImportBatch $batch, array $mapping): array
    {
        $headers = $batch->headers ?? [];
        $clean = [];
        $errors = [];
        foreach ($importer->columns() as $key => $column) {
            $heading = $mapping[$key] ?? null;
            $heading = $heading === '' ? null : $heading;
            if ($heading !== null && ! in_array($heading, $headers, true)) {
                $errors["mapping.{$key}"] = "Column \"{$heading}\" is not in the file.";
            }
            if ($heading === null && ! empty($column['required'])) {
                $errors["mapping.{$key}"] = "Map a column to {$column['label']}.";
            }
            $clean[$key] = $heading;
        }
        $used = array_filter($clean);
        if (count($used) !== count(array_unique($used))) {
            $errors['mapping'] = 'Each file column can be mapped to only one field.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function ensureStatus(ImportBatch $batch, array $allowed): void
    {
        if (! in_array($batch->status, $allowed, true)) {
            throw ValidationException::withMessages(['batch' => "This import is {$batch->status} and cannot be changed."]);
        }
    }
}
