<?php

namespace App\Services\Stock;

use App\Models\Stock\StockDocument;
use App\Models\Stock\StockLedgerEntry;
use App\Models\Stock\StockLevel;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The only code that changes stock. Posting and reversal run in one database
 * transaction with the document and the SKU/location balance row locked, so
 * a document cannot be posted twice and concurrent issues cannot overdraw.
 */
class StockPostingService
{
    public function __construct(
        private readonly StockDocumentService $documents,
        private readonly StructureScope $scope,
        private readonly ActivityLogger $activity,
    ) {}

    public function post(StockDocument $document, User $user, ?string $vendorCheckReason = null): StockDocument
    {
        $this->scope->ensureFactory($user, $document->factory_id);

        return DB::transaction(function () use ($document, $user, $vendorCheckReason) {
            $document = StockDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== StockDocument::DRAFT) {
                throw new ConflictHttpException("{$document->number} is already {$document->status}.");
            }

            // Re-check every reference at posting time (things may have been deactivated since the draft).
            $this->documents->validated($document->type, [
                ...$document->only(['factory_id', 'location_id', 'sku_id', 'vendor_id', 'department_id', 'recipient', 'job_no', 'reference', 'remarks']),
                'document_date' => $document->document_date->toDateString(),
                'quantity' => (string) $document->quantity,
                'received_qty' => (string) $document->received_qty,
                'rejected_qty' => (string) $document->rejected_qty,
            ], $user);

            if ($document->type === StockDocument::INWARD) {
                $this->checkVendor($document, $user, $vendorCheckReason);
            }
            if ($document->type === StockDocument::OPENING) {
                $this->checkOpening($document);
            }

            $this->apply($document, $document->stockEffect(), false, $user);
            $document->forceFill(['status' => StockDocument::POSTED, 'posted_by' => $user->id, 'posted_at' => now()])->save();
            $this->activity->log("stock.{$document->type}.posted", $document, [
                'number' => $document->number, 'effect' => $document->stockEffect(), 'vendor_mapped' => $document->vendor_mapped,
            ], StockDocumentService::LABELS[$document->type]." {$document->number} posted", $user);

            return $document;
        });
    }

    /** Compensating entry: the posted document stays in history, marked reversed. */
    public function reverse(StockDocument $document, User $user, string $reason): StockDocument
    {
        $this->scope->ensureFactory($user, $document->factory_id);

        return DB::transaction(function () use ($document, $user, $reason) {
            $document = StockDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== StockDocument::POSTED) {
                throw new ConflictHttpException("Only posted entries can be reversed; {$document->number} is {$document->status}.");
            }

            $this->apply($document, bcmul($document->stockEffect(), '-1', 3), true, $user);
            $document->forceFill([
                'status' => StockDocument::REVERSED, 'reversed_by' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason,
            ])->save();
            $this->activity->log("stock.{$document->type}.reversed", $document, ['number' => $document->number, 'reason' => $reason],
                StockDocumentService::LABELS[$document->type]." {$document->number} reversed", $user);

            return $document;
        });
    }

    /**
     * Admin correction of a posted entry: the old stock effect is taken out
     * and the corrected one put in (two ledger rows), stock is recalculated,
     * and the change is logged with old and new values and the reason.
     */
    public function correct(StockDocument $document, array $input, User $user, string $reason, ?string $vendorCheckReason = null): StockDocument
    {
        $this->scope->ensureFactory($user, $document->factory_id);

        return DB::transaction(function () use ($document, $input, $user, $reason, $vendorCheckReason) {
            $document = StockDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== StockDocument::POSTED) {
                throw new ConflictHttpException("Only posted entries are corrected this way; {$document->number} is {$document->status}.");
            }
            $data = $this->documents->validated($document->type, $input, $user);

            $old = $document->replicate();
            $old->setAttribute('id', $document->id);
            $tracked = ['document_date', 'factory_id', 'location_id', 'sku_id', 'vendor_id', 'department_id', 'quantity', 'received_qty', 'rejected_qty', 'recipient', 'job_no', 'reference', 'remarks'];
            $before = $this->snapshot($document, $tracked);

            $document->fill($data);
            if ($document->type === StockDocument::INWARD) {
                $this->checkVendor($document, $user, $vendorCheckReason);
            }
            if ($document->type === StockDocument::OPENING) {
                $this->checkOpening($document);
            }

            // Increases first, so a balance never dips below its final value.
            $moves = [[$old, bcmul($old->stockEffect(), '-1', 3), true], [$document, $document->stockEffect(), false]];
            usort($moves, fn ($a, $b) => bccomp($b[1], $a[1], 3));
            foreach ($moves as [$doc, $effect, $reversal]) {
                $this->apply($doc, $effect, $reversal, $user);
            }

            $document->forceFill(['edit_count' => $document->edit_count + 1, 'edited_by' => $user->id, 'edited_at' => now()])->save();
            $changes = $this->activity->diff($before, $this->snapshot($document, $tracked));
            $this->activity->log("stock.{$document->type}.edited", $document, ['number' => $document->number, 'reason' => $reason, 'changes' => $changes],
                StockDocumentService::LABELS[$document->type]." {$document->number} edited after posting: {$reason}", $user);

            return $document;
        });
    }

    /**
     * Admin deletion. Drafts/cancelled entries never touched stock and are
     * removed. Posted entries have their stock effect reversed first; posted
     * and reversed entries are then hidden (soft-deleted) so the stock history
     * stays complete. A full copy is written to the activity log.
     */
    public function delete(StockDocument $document, User $user, ?string $reason): void
    {
        $this->scope->ensureFactory($user, $document->factory_id);

        DB::transaction(function () use ($document, $user, $reason) {
            $document = StockDocument::query()->lockForUpdate()->findOrFail($document->id);
            $copy = $document->only(['id', 'type', 'number', 'status', 'document_date', 'factory_id', 'location_id', 'sku_id', 'vendor_id', 'department_id',
                'quantity', 'received_qty', 'rejected_qty', 'recipient', 'job_no', 'reference', 'remarks', 'posted_at', 'posted_by']);
            $label = StockDocumentService::LABELS[$document->type];

            if (in_array($document->status, [StockDocument::DRAFT, StockDocument::CANCELLED], true)) {
                $document->forceDelete();
            } else {
                if ($document->status === StockDocument::POSTED) {
                    $this->apply($document, bcmul($document->stockEffect(), '-1', 3), true, $user);
                }
                $document->forceFill(['deleted_by' => $user->id, 'delete_reason' => $reason])->save();
                $document->delete();
            }

            $this->activity->log("stock.{$document->type}.deleted", null, ['entry' => $copy, 'reason' => $reason],
                "{$label} {$copy['number']} ({$copy['status']}) deleted".($reason ? ": {$reason}" : ''), $user);
        });
    }

    /** @param list<string> $fields */
    private function snapshot(StockDocument $document, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn ($f) => [$f => match (true) {
            $document->{$f} instanceof \DateTimeInterface => $document->{$f}->format('Y-m-d'),
            in_array($f, ['quantity', 'received_qty', 'rejected_qty'], true) => StockOverview::number($document->{$f}),
            default => $document->{$f},
        }])->all();
    }

    private function apply(StockDocument $document, string $effect, bool $reversal, User $user): void
    {
        $now = now();
        StockLevel::query()->insertOrIgnore([
            'sku_id' => $document->sku_id, 'factory_id' => $document->factory_id, 'location_id' => $document->location_id,
            'quantity' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $level = StockLevel::query()->where('sku_id', $document->sku_id)->where('location_id', $document->location_id)->lockForUpdate()->firstOrFail();

        $before = bcadd((string) $level->quantity, '0', 3);
        $after = bcadd($before, $effect, 3);
        if (bccomp($after, '0', 3) < 0) {
            $document->loadMissing(['sku', 'location']);
            $before = StockOverview::number($before);
            throw ValidationException::withMessages(['quantity' => $reversal
                ? "Cannot reverse {$document->number}: only {$before} of {$document->sku->sku} is left at {$document->location->code}; the stock has already been used."
                : "Insufficient stock: only {$before} of {$document->sku->sku} is available at {$document->location->code}.",
            ]);
        }

        $sign = $reversal ? '-1' : '1';
        StockLedgerEntry::create([
            'document_id' => $document->id,
            'kind' => $document->type,
            'is_reversal' => $reversal,
            'sku_id' => $document->sku_id,
            'factory_id' => $document->factory_id,
            'location_id' => $document->location_id,
            'qty_in' => bcmul($document->type === StockDocument::INWARD ? (string) $document->received_qty : ($document->type === StockDocument::OPENING ? (string) $document->quantity : '0'), $sign, 3),
            'qty_rejected' => bcmul($document->type === StockDocument::INWARD ? (string) $document->rejected_qty : '0', $sign, 3),
            'qty_out' => bcmul($document->type === StockDocument::OUTWARD ? (string) $document->quantity : '0', $sign, 3),
            'balance_after' => $after,
            'created_by' => $user->id,
            'posted_at' => $now,
            'created_at' => $now,
        ]);
        $level->forceFill(['quantity' => $after])->save();
    }

    private function checkVendor(StockDocument $document, User $user, ?string $reason): void
    {
        $mapped = (bool) $this->documents->vendorMapped($document);
        if (! $mapped) {
            $document->loadMissing(['vendor', 'sku']);
            if (! $user->can('inward.override-vendor-check')) {
                throw ValidationException::withMessages(['vendor_id' => "{$document->vendor->name} is not mapped to SKU {$document->sku->sku}. Add the mapping in Vendor Master first."]);
            }
            $reason = trim((string) ($reason ?? $document->vendor_check_reason));
            if ($reason === '') {
                throw ValidationException::withMessages(['vendor_check_reason' => "{$document->vendor->name} is not mapped to SKU {$document->sku->sku}. Give a reason to post it anyway."]);
            }
            $document->vendor_check_reason = mb_substr($reason, 0, 500);
        }
        $document->vendor_mapped = $mapped;
    }

    /** Opening stock is set once per SKU and location (unless the earlier one was reversed). */
    private function checkOpening(StockDocument $document): void
    {
        $hasHistory = StockDocument::query()
            ->where('sku_id', $document->sku_id)->where('location_id', $document->location_id)
            ->where('status', StockDocument::POSTED)->whereKeyNot($document->id)->exists();
        if ($hasHistory) {
            throw ValidationException::withMessages(['quantity' => 'This SKU already has posted stock entries at this location, so opening stock can no longer be set. Use inward/outward entries instead.']);
        }
    }
}
