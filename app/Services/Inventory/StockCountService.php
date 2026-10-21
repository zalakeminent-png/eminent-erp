<?php

namespace App\Services\Inventory;

use App\Models\Inventory\Balance;
use App\Models\Inventory\StockCountLine;
use App\Models\Inventory\StockCountSession;
use App\Models\MasterData\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ActivityLogger;
use App\Services\MasterData\NumberSequence;
use App\Services\Structure\StructureScope;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Physical stock counting: draft → counting (snapshot at cutoff, locations
 * optionally locked) → submitted → approved → posted. Posting creates
 * variance ledger entries (counted − snapshot), so movements made after
 * the cutoff in an unlocked count are preserved.
 */
class StockCountService
{
    /** Statuses that are physically counted (in-transit stock is not on a shelf). */
    public const COUNTABLE = ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap'];

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly NumberSequence $numbers,
        private readonly StructureScope $scope,
        private readonly ActivityLogger $logger,
    ) {}

    public function create(array $data, User $user): StockCountSession
    {
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $this->scope->ensureFactory($user, $warehouse->factory_id);
        $locationIds = array_values(array_unique(array_map('intval', $data['location_ids'] ?? [])));
        if ($locationIds && WarehouseLocation::query()->whereKey($locationIds)->where('warehouse_id', $warehouse->id)->count() !== count($locationIds)) {
            throw ValidationException::withMessages(['location_ids' => 'All locations must belong to the selected warehouse.']);
        }

        $session = StockCountSession::create([
            'count_number' => $this->numbers->formatted('inventory-count', 'CNT-', 6),
            'factory_id' => $warehouse->factory_id,
            'warehouse_id' => $warehouse->id,
            'location_ids' => $locationIds ?: null,
            'category_ids' => ($data['category_ids'] ?? null) ?: null,
            'item_ids' => ($data['item_ids'] ?? null) ?: null,
            'lock_locations' => (bool) ($data['lock_locations'] ?? true),
            'remarks' => $data['remarks'] ?? null,
            'status' => StockCountSession::DRAFT,
            'created_by' => $user->id,
        ]);
        $this->logger->log('inventory.count.created', $session, ['number' => $session->count_number], null, $user);

        return $session;
    }

    /** Takes the system snapshot at the cutoff and (optionally) locks the locations. */
    public function start(StockCountSession $session, User $user): StockCountSession
    {
        return $this->transition($session, [StockCountSession::DRAFT], StockCountSession::COUNTING, $user, function (StockCountSession $s) use ($user) {
            $balances = Balance::query()
                ->where('warehouse_id', $s->warehouse_id)
                ->whereIn('stock_status', self::COUNTABLE)
                ->when($s->location_ids, fn ($q) => $q->whereIn('location_id', $s->location_ids))
                ->when($s->item_ids, fn ($q) => $q->whereIn('item_id', $s->item_ids))
                ->when($s->category_ids, fn ($q) => $q->whereIn('item_id', Item::query()->whereIn('category_id', $s->category_ids)->select('id')))
                ->where('quantity', '!=', 0)
                ->lockForUpdate()->get();

            foreach ($balances as $b) {
                $line = new StockCountLine([
                    'item_id' => $b->item_id, 'location_id' => $b->location_id, 'stock_status' => $b->stock_status,
                    'base_uom_id' => $b->base_uom_id, 'system_quantity' => $b->quantity,
                ]);
                $line->session_id = $s->id;
                $line->save();
            }
            $s->forceFill(['cutoff_at' => now(), 'started_by' => $user->id]);
        });
    }

    /**
     * Records counted quantities (base UOM). Entries address an existing line
     * by line_id, or add a found item by item_id + location_id (+ status).
     */
    public function record(StockCountSession $session, array $entries, User $user): StockCountSession
    {
        $this->scope->ensureFactory($user, $session->factory_id);

        return DB::transaction(function () use ($session, $entries, $user) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($session->status !== StockCountSession::COUNTING) {
                throw new ConflictHttpException('Counts can only be entered while counting is in progress.');
            }

            foreach (array_values($entries) as $i => $entry) {
                $qty = (string) ($entry['counted_quantity'] ?? '');
                if (! Decimal::isNumeric($qty) || str_starts_with($qty, '-') || Decimal::decimals($qty) > 6) {
                    throw ValidationException::withMessages(["entries.{$i}.counted_quantity" => 'Enter the counted quantity (zero or more).']);
                }

                if (! empty($entry['line_id'])) {
                    $line = $session->lines()->whereKey($entry['line_id'])->first()
                        ?? throw ValidationException::withMessages(["entries.{$i}.line_id" => 'Unknown count line.']);
                } else {
                    $line = $this->foundLine($session, $entry, $i);
                }
                $uom = $line->baseUom;
                if ($uom && Decimal::decimals($qty) > $uom->decimal_precision) {
                    throw ValidationException::withMessages(["entries.{$i}.counted_quantity" => "{$uom->code} allows at most {$uom->decimal_precision} decimals."]);
                }
                $line->forceFill([
                    'counted_quantity' => $qty, 'remarks' => $entry['remarks'] ?? $line->remarks,
                    'counted_by' => $user->id, 'counted_at' => now(),
                ])->save();
            }

            return $session->refresh();
        });
    }

    public function submit(StockCountSession $session, User $user): StockCountSession
    {
        return $this->transition($session, [StockCountSession::COUNTING], StockCountSession::SUBMITTED, $user, function (StockCountSession $s) use ($user) {
            $missing = $s->lines()->whereNull('counted_quantity')->count();
            if ($missing) {
                throw ValidationException::withMessages(['lines' => "{$missing} line(s) have not been counted yet. Enter 0 for items that were not found."]);
            }
            $s->forceFill(['submitted_by' => $user->id, 'submitted_at' => now()]);
        });
    }

    public function approve(StockCountSession $session, User $user): StockCountSession
    {
        return $this->transition($session, [StockCountSession::SUBMITTED], StockCountSession::APPROVED, $user, function (StockCountSession $s) use ($user) {
            if ($s->submitted_by === $user->id) {
                throw new AuthorizationException('Count variances must be approved by someone other than the person who submitted the count.');
            }
            $s->forceFill(['approved_by' => $user->id, 'approved_at' => now()]);
        });
    }

    /** Posts the variances and releases the location lock. */
    public function post(StockCountSession $session, User $user): StockCountSession
    {
        return $this->transition($session, [StockCountSession::APPROVED], StockCountSession::POSTED, $user, function (StockCountSession $s) use ($user) {
            $movements = $s->lines()->get()
                ->map(fn (StockCountLine $l) => [$l, $l->variance()])
                ->filter(fn ($pair) => $pair[1] !== null && bccomp($pair[1], '0', 6) !== 0)
                ->map(fn ($pair) => new Movement($pair[0]->item_id, $pair[0]->location_id, $pair[0]->stock_status, $pair[1], null, null, 'count'))
                ->values()->all();

            if ($movements) {
                $transaction = $this->posting->post('stock_count', $movements, new PostingContext(
                    idempotencyKey: "count:{$s->id}:post",
                    userId: $user->id,
                    sourceDocumentType: 'stock_count',
                    sourceDocumentId: $s->id,
                    remarks: "Stock count {$s->count_number} variance",
                    ignoreCountLocks: true,
                ));
                $s->transaction_id = $transaction->id;
            }
            $s->forceFill(['posted_by' => $user->id, 'posted_at' => now()]);
        });
    }

    public function cancel(StockCountSession $session, User $user): StockCountSession
    {
        return $this->transition($session, [StockCountSession::DRAFT, StockCountSession::COUNTING, StockCountSession::SUBMITTED], StockCountSession::CANCELLED, $user);
    }

    private function foundLine(StockCountSession $session, array $entry, int $i): StockCountLine
    {
        $status = $entry['stock_status'] ?? 'available';
        $location = WarehouseLocation::query()->find($entry['location_id'] ?? 0);
        $item = Item::query()->find($entry['item_id'] ?? 0);
        if (! $location || $location->warehouse_id !== $session->warehouse_id || ($session->location_ids && ! in_array($location->id, $session->location_ids, true))) {
            throw ValidationException::withMessages(["entries.{$i}.location_id" => 'The location is not part of this count.']);
        }
        if (! $item || ! in_array($status, self::COUNTABLE, true)) {
            throw ValidationException::withMessages(["entries.{$i}.item_id" => 'Select a valid item and stock status.']);
        }

        $line = $session->lines()->firstOrNew(['item_id' => $item->id, 'location_id' => $location->id, 'stock_status' => $status]);
        if (! $line->exists) {
            // Not in the snapshot: the system held none at the cutoff.
            $line->forceFill(['base_uom_id' => $item->base_uom_id, 'system_quantity' => 0]);
        }

        return $line;
    }

    private function transition(StockCountSession $session, array $from, string $to, User $user, ?callable $apply = null): StockCountSession
    {
        $this->scope->ensureFactory($user, $session->factory_id);

        return DB::transaction(function () use ($session, $from, $to, $user, $apply) {
            $session = StockCountSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, $from, true)) {
                throw new ConflictHttpException("Count {$session->count_number} is {$session->status}.");
            }
            $previous = $session->status;
            if ($apply) {
                $apply($session);
            }
            $session->status = $to;
            $session->save();
            $this->logger->log("inventory.count.{$to}", $session, ['number' => $session->count_number, 'from' => $previous, 'to' => $to], null, $user);

            return $session->refresh();
        });
    }
}
