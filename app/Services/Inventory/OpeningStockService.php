<?php

namespace App\Services\Inventory;

use App\Jobs\PostOpeningStock;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\OpeningStockImport;
use App\Models\Inventory\OpeningStockRow;
use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\MasterData\NumberSequence;
use App\Services\MasterData\UomConverter;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use App\Support\Spreadsheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Opening stock from Excel/CSV:
 *   upload → map columns → validate & preview (draft) → submit → approve → post.
 * Rejected rows are never posted. Opening stock is refused for any
 * item/location/status that already has stock history, so it cannot be
 * loaded twice. Posting is one atomic, idempotent transaction; a posted
 * import can only be undone by a compensating reversal.
 */
class OpeningStockService
{
    private const DISK = 'local';

    /** key => [label, required, header aliases] */
    public const COLUMNS = [
        'item_code' => ['Item Code', true, ['item', 'item code', 'sku', 'code']],
        'item_name' => ['Item Name (reference only)', false, ['item name', 'name', 'description']],
        'warehouse_code' => ['Warehouse Code', true, ['warehouse', 'warehouse code', 'store']],
        'location_code' => ['Location Code', true, ['location', 'location code', 'bin', 'rack']],
        'quantity' => ['Quantity', true, ['qty', 'quantity', 'opening quantity', 'opening qty']],
        'uom' => ['UOM', false, ['uom', 'unit', 'unit of measure']],
        'stock_status' => ['Stock Status', false, ['status', 'stock status']],
        'unit_cost' => ['Unit Cost', false, ['cost', 'unit cost', 'rate']],
        'remarks' => ['Remarks', false, ['remarks', 'notes', 'comment']],
    ];

    public const IMPORTABLE_STATUSES = ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap'];

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly UomConverter $converter,
        private readonly NumberSequence $numbers,
        private readonly StructureScope $scope,
        private readonly ActivityLogger $logger,
    ) {}

    public function template(): BinaryFileResponse
    {
        return Spreadsheet::download('opening-stock-template', array_map(fn ($c) => $c[0], self::COLUMNS), []);
    }

    public function upload(UploadedFile $file, User $user): OpeningStockImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('opening-stock', Str::random(40).".{$extension}", self::DISK);

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

        $max = (int) config('erp.inventory.opening_stock.max_rows');
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

        $import = OpeningStockImport::create([
            'import_number' => $this->numbers->formatted('inventory-opening', 'OPN-', 5),
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'path' => $path,
            'status' => OpeningStockImport::UPLOADED,
            'headers' => $headers,
            'mapping' => $this->suggestMapping($headers),
            'total_rows' => $count,
            'created_by' => $user->id,
        ]);
        $this->logger->log('inventory.opening.uploaded', $import, ['file' => $import->original_name, 'rows' => $count], 'Opening stock file uploaded', $user);

        return $import;
    }

    /**
     * Validates every row with the mapping; stores accepted/rejected rows
     * as the preview. Re-running replaces the previous preview.
     *
     * @param  array<string, ?string>  $mapping  column key => source heading
     */
    public function validate(OpeningStockImport $import, array $mapping, User $user): OpeningStockImport
    {
        $this->ensureStatus($import, [OpeningStockImport::UPLOADED, OpeningStockImport::DRAFT]);
        $mapping = $this->checkMapping($import, $mapping);

        $parsed = [];
        foreach (Spreadsheet::rows(Storage::disk(self::DISK)->path($import->path), pathinfo($import->path, PATHINFO_EXTENSION)) as $number => $cells) {
            if ($number === 1 || ! array_filter($cells, fn ($v) => $v !== '')) {
                continue;
            }
            $raw = [];
            foreach ($mapping as $key => $heading) {
                $index = $heading === null ? false : array_search($heading, $import->headers, true);
                $raw[$key] = $index === false ? '' : trim((string) ($cells[$index] ?? ''));
            }
            $parsed[$number] = $raw;
        }

        $results = $this->evaluate($parsed, $user, $import);

        DB::transaction(function () use ($import, $results, $mapping, $user) {
            $import->rows()->delete();
            foreach (array_chunk($results, 500) as $chunk) {
                OpeningStockRow::query()->insert(array_map(fn ($r) => [
                    ...$r, 'import_id' => $import->id, 'raw' => json_encode($r['raw']), 'errors' => $r['errors'] ? json_encode($r['errors']) : null, 'created_at' => now(),
                ], $chunk));
            }
            $accepted = collect($results)->where('status', 'accepted')->count();
            $import->forceFill([
                'mapping' => $mapping, 'status' => OpeningStockImport::DRAFT,
                'total_rows' => count($results), 'accepted_rows' => $accepted, 'rejected_rows' => count($results) - $accepted,
            ])->save();
            $this->logger->log('inventory.opening.validated', $import, ['accepted' => $accepted, 'rejected' => count($results) - $accepted], null, $user);
        });

        return $import->refresh();
    }

    public function submit(OpeningStockImport $import, User $user): OpeningStockImport
    {
        return $this->transition($import, [OpeningStockImport::DRAFT], OpeningStockImport::SUBMITTED, $user, function (OpeningStockImport $i) use ($user) {
            if ($i->accepted_rows < 1) {
                throw ValidationException::withMessages(['rows' => 'There are no accepted rows to submit. Fix the file and validate again.']);
            }
            $i->forceFill(['submitted_by' => $user->id, 'submitted_at' => now()]);
        });
    }

    public function approve(OpeningStockImport $import, User $user): OpeningStockImport
    {
        return $this->transition($import, [OpeningStockImport::SUBMITTED], OpeningStockImport::APPROVED, $user, function (OpeningStockImport $i) use ($user) {
            $i->forceFill(['approved_by' => $user->id, 'approved_at' => now()]);
        });
    }

    /** Back to draft for corrections (before posting). */
    public function reject(OpeningStockImport $import, User $user): OpeningStockImport
    {
        return $this->transition($import, [OpeningStockImport::SUBMITTED, OpeningStockImport::APPROVED], OpeningStockImport::DRAFT, $user);
    }

    /** Posts the accepted rows; large imports run on the queue. */
    public function post(OpeningStockImport $import, User $user): OpeningStockImport
    {
        $import = $this->transition($import, [OpeningStockImport::APPROVED, OpeningStockImport::FAILED], OpeningStockImport::POSTING, $user, function (OpeningStockImport $i) use ($user) {
            $i->forceFill(['posted_by' => $user->id, 'failure_message' => null]);
        });

        if ($import->accepted_rows > (int) config('erp.inventory.opening_stock.queue_threshold')) {
            PostOpeningStock::dispatch($import->id);

            return $import->refresh();
        }

        return $this->run($import);
    }

    /** Performs the posting (inline or from the queue job). */
    public function run(OpeningStockImport $import): OpeningStockImport
    {
        try {
            $rows = $import->rows()->where('status', 'accepted')->get();
            // Re-check history at posting time (another import may have posted meanwhile).
            $conflicts = $rows->filter(fn (OpeningStockRow $r) => $this->hasHistory($r->item_id, $r->location_id, $r->stock_status));
            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages(['rows' => 'Rows '.$conflicts->pluck('row_number')->take(20)->implode(', ').' now have stock history; validate the file again.']);
            }

            $movements = $rows->map(fn (OpeningStockRow $r) => new Movement(
                $r->item_id, $r->location_id, $r->stock_status, Decimal::normalize((string) $r->base_quantity),
                $r->unit_cost !== null ? bcdiv((string) $r->unit_cost, (string) $r->conversion_factor, 6) : null,
                null, 'opening',
            ))->all();

            $transaction = $this->posting->post('opening_stock', $movements, new PostingContext(
                idempotencyKey: "opening:{$import->id}:post",
                userId: $import->posted_by,
                sourceDocumentType: 'opening_stock',
                sourceDocumentId: $import->id,
                remarks: "Opening stock {$import->import_number}",
            ));

            $import->forceFill(['status' => OpeningStockImport::POSTED, 'transaction_id' => $transaction->id, 'posted_at' => now()])->save();
            $this->logger->log('inventory.opening.posted', $import, ['transaction' => $transaction->transaction_number, 'rows' => $rows->count()], "Opening stock {$import->import_number} posted", User::find($import->posted_by));
        } catch (Throwable $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : 'Posting failed. Nothing was posted.';
            $import->forceFill(['status' => OpeningStockImport::FAILED, 'failure_message' => Str::limit($message, 2000)])->save();
            if (! $e instanceof ValidationException) {
                report($e);
            }
        }

        return $import->refresh();
    }

    /** Compensating reversal of a posted import (never deletes ledger history). */
    public function reverse(OpeningStockImport $import, string $reason, User $user): OpeningStockImport
    {
        return $this->transition($import, [OpeningStockImport::POSTED], OpeningStockImport::REVERSED, $user, function (OpeningStockImport $i) use ($user, $reason) {
            $reversal = $this->posting->reverse($i->transaction, $user->id, $reason);
            $i->forceFill(['reversal_transaction_id' => $reversal->id, 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => Str::limit($reason, 500, '')]);
        });
    }

    public function errorReport(OpeningStockImport $import): BinaryFileResponse
    {
        $headers = ['Row', ...array_map(fn ($c) => $c[0], self::COLUMNS), 'Errors'];
        $rows = $import->rows()->where('status', 'rejected')->cursor()->map(fn (OpeningStockRow $r) => [
            $r->row_number, ...array_map(fn ($k) => $r->raw[$k] ?? '', array_keys(self::COLUMNS)), implode('; ', $r->errors ?? []),
        ]);

        return Spreadsheet::download("{$import->import_number}-errors", $headers, $rows);
    }

    // ---- validation --------------------------------------------------------

    /**
     * @param  array<int, array<string, string>>  $parsed  row number => mapped values
     * @return list<array<string, mixed>>
     */
    private function evaluate(array $parsed, User $user, OpeningStockImport $import): array
    {
        $codes = fn (string $key) => collect($parsed)->pluck($key)->filter()->map(fn ($c) => mb_strtolower($c))->unique()->values();
        $items = Item::query()->with('baseUom')->whereIn(DB::raw('LOWER(code)'), $codes('item_code'))->get()->keyBy(fn ($i) => mb_strtolower($i->code));
        $warehouses = Warehouse::query()->whereIn(DB::raw('LOWER(code)'), $codes('warehouse_code'))->get()->keyBy(fn ($w) => mb_strtolower($w->code));
        $locations = WarehouseLocation::query()->whereIn('warehouse_id', $warehouses->pluck('id'))->get()
            ->keyBy(fn ($l) => $l->warehouse_id.'|'.mb_strtolower($l->code));
        $uoms = UnitOfMeasure::query()->get()->keyBy(fn ($u) => mb_strtolower($u->code));
        $allowedFactories = $this->scope->factoryIds($user);

        // Identities used by other open/posted imports.
        $pending = OpeningStockRow::query()->where('status', 'accepted')->where('import_id', '!=', $import->id)
            ->whereHas('import', fn ($q) => $q->whereIn('status', [OpeningStockImport::SUBMITTED, OpeningStockImport::APPROVED, OpeningStockImport::POSTING, OpeningStockImport::POSTED]))
            ->get(['item_id', 'location_id', 'stock_status'])->map(fn ($r) => "{$r->item_id}|{$r->location_id}|{$r->stock_status}")->flip();

        $results = [];
        $identityCounts = [];
        foreach ($parsed as $number => $raw) {
            $errors = [];
            $item = $items->get(mb_strtolower($raw['item_code']));
            $warehouse = $warehouses->get(mb_strtolower($raw['warehouse_code']));
            $location = $warehouse ? $locations->get($warehouse->id.'|'.mb_strtolower($raw['location_code'])) : null;
            $status = $raw['stock_status'] !== '' ? strtolower(str_replace([' ', '-'], '_', $raw['stock_status'])) : 'available';
            $uom = $raw['uom'] !== '' ? $uoms->get(mb_strtolower($raw['uom'])) : $item?->baseUom;
            $base = null;
            $factor = null;

            if ($raw['item_code'] === '') {
                $errors[] = 'Item code is required.';
            } elseif (! $item) {
                $errors[] = "Item {$raw['item_code']} does not exist.";
            } elseif (! $item->is_active || ! $item->is_stock_item || ! $item->track_inventory) {
                $errors[] = "Item {$item->code} is inactive or not a tracked stock item.";
            }
            if (! $warehouse) {
                $errors[] = $raw['warehouse_code'] === '' ? 'Warehouse code is required.' : "Warehouse {$raw['warehouse_code']} does not exist.";
            } elseif (! $warehouse->is_active) {
                $errors[] = "Warehouse {$warehouse->code} is inactive.";
            } elseif ($allowedFactories !== null && ! in_array($warehouse->factory_id, $allowedFactories, true)) {
                $errors[] = "You do not have access to warehouse {$warehouse->code}.";
            }
            if ($warehouse && ! $location) {
                $errors[] = $raw['location_code'] === '' ? 'Location code is required.' : "Location {$raw['location_code']} does not exist in {$warehouse->code}.";
            } elseif ($location && ! $location->is_active) {
                $errors[] = "Location {$location->code} is inactive.";
            }
            if (! in_array($status, self::IMPORTABLE_STATUSES, true)) {
                $errors[] = "Stock status {$raw['stock_status']} is not allowed for opening stock.";
            }
            if ($raw['uom'] !== '' && ! $uom) {
                $errors[] = "UOM {$raw['uom']} does not exist.";
            }
            if (! Decimal::isNumeric($raw['quantity']) || str_starts_with($raw['quantity'], '-') || ! Decimal::isPositive($raw['quantity'])) {
                $errors[] = 'Quantity must be a number greater than zero.';
            } elseif (Decimal::decimals($raw['quantity']) > 6) {
                $errors[] = 'Quantity has more than 6 decimals.';
            } elseif ($item?->baseUom && $uom) {
                try {
                    $converted = $this->converter->convert(Decimal::normalize($raw['quantity']), $uom, $item->baseUom, $item);
                    [$base, $factor] = [$converted['quantity'], $converted['factor']];
                } catch (ValidationException $e) {
                    $errors[] = collect($e->errors())->flatten()->first();
                }
            }
            if ($raw['unit_cost'] !== '' && (! Decimal::isNumeric($raw['unit_cost']) || str_starts_with($raw['unit_cost'], '-') || Decimal::decimals($raw['unit_cost']) > 6)) {
                $errors[] = 'Unit cost must be zero or a positive number (max 6 decimals).';
            }
            if (mb_strlen($raw['remarks']) > 500) {
                $errors[] = 'Remarks may not exceed 500 characters.';
            }

            $identity = $item && $location ? "{$item->id}|{$location->id}|{$status}" : null;
            if ($identity) {
                $identityCounts[$identity] = ($identityCounts[$identity] ?? 0) + 1;
                if ($this->hasHistory($item->id, $location->id, $status)) {
                    $errors[] = "{$item->code} at {$location->code} ({$status}) already has stock transactions. Use a stock adjustment instead.";
                } elseif ($pending->has($identity)) {
                    $errors[] = "{$item->code} at {$location->code} ({$status}) is already in another opening stock import.";
                }
            }

            $results[] = [
                'row_number' => $number,
                'raw' => $raw,
                'errors' => $errors,
                '_identity' => $identity,
                'item_id' => $item?->id,
                'location_id' => $location?->id,
                'stock_status' => in_array($status, self::IMPORTABLE_STATUSES, true) ? $status : null,
                'quantity' => Decimal::isNumeric($raw['quantity']) && Decimal::decimals($raw['quantity']) <= 6 && strlen($raw['quantity']) < 20 ? Decimal::normalize($raw['quantity']) : null,
                'uom_id' => $uom?->id,
                'conversion_factor' => $factor,
                'base_quantity' => $base,
                'unit_cost' => $raw['unit_cost'] !== '' && Decimal::isNumeric($raw['unit_cost']) && ! str_starts_with($raw['unit_cost'], '-') ? Decimal::normalize($raw['unit_cost']) : null,
                'remarks' => $raw['remarks'] !== '' ? mb_substr($raw['remarks'], 0, 500) : null,
            ];
        }

        // Every occurrence of a duplicated item/location/status is rejected.
        return array_map(function (array $r) use ($identityCounts) {
            if ($r['_identity'] && $identityCounts[$r['_identity']] > 1) {
                $r['errors'][] = 'The same item, location and status appears more than once in this file.';
            }
            unset($r['_identity']);
            $r['status'] = $r['errors'] ? 'rejected' : 'accepted';

            return $r;
        }, $results);
    }

    private function hasHistory(int $itemId, int $locationId, string $status): bool
    {
        return LedgerEntry::query()->where('item_id', $itemId)->where('location_id', $locationId)->where('stock_status', $status)->exists();
    }

    private function suggestMapping(array $headers): array
    {
        $mapping = [];
        foreach (self::COLUMNS as $key => [$label, , $aliases]) {
            $mapping[$key] = collect($headers)->first(fn ($h) => in_array(mb_strtolower(trim($h)), [mb_strtolower($label), $key, ...$aliases], true));
        }

        return $mapping;
    }

    private function checkMapping(OpeningStockImport $import, array $mapping): array
    {
        $clean = [];
        $errors = [];
        foreach (self::COLUMNS as $key => [$label, $required]) {
            $heading = $mapping[$key] ?? null;
            if ($heading !== null && $heading !== '' && ! in_array($heading, $import->headers, true)) {
                $errors["mapping.{$key}"] = "Column {$heading} is not in the file.";
            }
            if ($required && ($heading === null || $heading === '')) {
                $errors["mapping.{$key}"] = "Map a column to {$label}.";
            }
            $clean[$key] = $heading !== '' ? $heading : null;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function ensureStatus(OpeningStockImport $import, array $allowed): void
    {
        if (! in_array($import->status, $allowed, true)) {
            throw new ConflictHttpException("Import {$import->import_number} is {$import->status}.");
        }
    }

    private function transition(OpeningStockImport $import, array $from, string $to, User $user, ?callable $apply = null): OpeningStockImport
    {
        return DB::transaction(function () use ($import, $from, $to, $user, $apply) {
            $import = OpeningStockImport::query()->lockForUpdate()->findOrFail($import->id);
            $this->ensureStatus($import, $from);
            $previous = $import->status;
            if ($apply) {
                $apply($import);
            }
            $import->status = $to;
            $import->save();
            $this->logger->log("inventory.opening.{$to}", $import, ['number' => $import->import_number, 'from' => $previous, 'to' => $to], null, $user);

            return $import->refresh();
        });
    }
}
