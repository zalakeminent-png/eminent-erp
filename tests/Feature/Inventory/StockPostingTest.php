<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\Balance;
use App\Models\Inventory\InventoryTransaction;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\StockCountSession;
use App\Services\Inventory\Movement;
use App\Services\Inventory\PostingContext;
use App\Services\Inventory\StockPostingService;
use App\Services\Inventory\StockQuery;
use App\Services\MasterData\ItemUsage;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class StockPostingTest extends InventoryTestCase
{
    private function engine(): StockPostingService
    {
        return app(StockPostingService::class);
    }

    public function test_posting_writes_ledger_entries_and_updates_balance(): void
    {
        $item = $this->item('RM-BAR');
        $tx = $this->receive($item, $this->rack, '100.5', '20');

        $this->assertSame('100.5', $this->onHand($item, $this->rack));
        $entry = $tx->entries->sole();
        $this->assertSame('100.5', Decimal::normalize((string) $entry->balance_after));
        $this->assertSame('2010', Decimal::normalize((string) $entry->value_delta));
        $this->assertSame($this->factory->id, $entry->factory_id);
        $this->assertBalancesMatchLedger();
    }

    public function test_item_base_uom_is_locked_once_stock_exists(): void
    {
        $item = $this->item('RM-LOCKUOM');
        $this->receive($item, $this->rack, '5');
        $this->actingAs($this->admin);

        $this->getJson($this->api("items/{$item->id}"))->assertOk()
            ->assertJsonPath('meta.locked_fields', ['code', 'base_uom_id', 'item_type']);
        $this->assertContains('it is referenced by inventory_ledger_entries', app(ItemUsage::class)->lockReasons($item));
    }

    public function test_user_with_posted_stock_cannot_be_deleted(): void
    {
        $item = $this->item('RM-AUTHOR');
        $keeper = $this->staff([]);
        $this->engine()->post('test', [new Movement($item->id, $this->rack->id, 'available', '1')], new PostingContext('author-1', $keeper->id));
        $this->actingAs($this->admin);

        $this->deleteJson($this->api("users/{$keeper->id}"))->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'posted stock transactions'));
        $this->assertNotNull($keeper->fresh());
    }

    public function test_verify_balances_command_detects_drift(): void
    {
        $item = $this->item('RM-VERIFY');
        $this->receive($item, $this->rack, '6');
        $this->artisan('inventory:verify-balances')->assertSuccessful();

        // Simulate a corrupted cache (balances are never edited by the app).
        DB::table('inventory_balances')->where('item_id', $item->id)->update(['quantity' => 7]);
        $this->artisan('inventory:verify-balances')->assertFailed();
    }

    public function test_negative_stock_is_prevented_unless_item_allows_it(): void
    {
        $item = $this->item('RM-NEG');
        $this->receive($item, $this->rack, '5');

        try {
            $this->engine()->post('test', [new Movement($item->id, $this->rack->id, 'available', '-6')], new PostingContext('neg-1', $this->admin->id));
            $this->fail('Expected insufficient stock.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Insufficient stock', $e->getMessage());
        }
        $this->assertSame('5', $this->onHand($item, $this->rack));
        $this->assertSame(1, LedgerEntry::count());

        $flexible = $this->item('RM-FLEX', 'KG', ['allow_negative_stock' => true]);
        $this->engine()->post('test', [new Movement($flexible->id, $this->rack->id, 'available', '-2')], new PostingContext('neg-2', $this->admin->id));
        $this->assertSame('-2', $this->onHand($flexible, $this->rack));

        // Even for flexible items, non-available statuses never go negative.
        $this->expectException(ValidationException::class);
        $this->engine()->post('test', [new Movement($flexible->id, $this->rack->id, 'qc_pending', '-1')], new PostingContext('neg-3', $this->admin->id));
    }

    public function test_repeated_idempotency_key_does_not_double_post(): void
    {
        $item = $this->item('RM-IDEM');
        $context = new PostingContext('grn:1:post', $this->admin->id);
        $first = $this->engine()->post('grn', [new Movement($item->id, $this->rack->id, 'available', '10')], $context);
        $second = $this->engine()->post('grn', [new Movement($item->id, $this->rack->id, 'available', '10')], $context);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('10', $this->onHand($item, $this->rack));
        $this->assertSame(1, InventoryTransaction::count());
    }

    public function test_failed_posting_rolls_back_every_line(): void
    {
        $a = $this->item('RM-A');
        $b = $this->item('RM-B');
        $this->receive($a, $this->rack, '10');

        try {
            $this->engine()->post('test', [
                new Movement($a->id, $this->rack->id, 'available', '-4'),
                new Movement($a->id, $this->rack2->id, 'available', '4'),
                new Movement($b->id, $this->rack->id, 'available', '-1'), // no stock
            ], new PostingContext('atomic-1', $this->admin->id));
            $this->fail('Expected failure.');
        } catch (ValidationException) {
        }

        $this->assertSame('10', $this->onHand($a, $this->rack));
        $this->assertSame('0', $this->onHand($a, $this->rack2));
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertBalancesMatchLedger();
    }

    public function test_ledger_is_immutable(): void
    {
        $item = $this->item('RM-IMM');
        $tx = $this->receive($item, $this->rack, '3');
        $entry = $tx->entries->sole();

        try {
            $entry->quantity = '300';
            $entry->save();
            $this->fail('Model guard should block updates.');
        } catch (LogicException) {
        }

        // The database itself refuses changes, even bypassing Eloquent.
        foreach ([
            fn () => DB::table('inventory_ledger_entries')->where('id', $entry->id)->update(['quantity' => 300]),
            fn () => DB::table('inventory_ledger_entries')->where('id', $entry->id)->delete(),
            fn () => DB::table('inventory_transactions')->where('id', $tx->id)->update(['remarks' => 'x']),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Database trigger should block the change.');
            } catch (Throwable $e) {
                $this->assertStringContainsStringIgnoringCase('immutable', $e->getMessage());
            }
        }
        $this->assertSame('3', Decimal::normalize((string) $entry->fresh()->quantity));
    }

    public function test_reversal_posts_compensating_entries_once(): void
    {
        $item = $this->item('RM-REV');
        $tx = $this->receive($item, $this->rack, '8', '5');

        $reversal = $this->engine()->reverse($tx, $this->admin->id, 'Wrong item');
        $again = $this->engine()->reverse($tx, $this->admin->id, 'Wrong item');

        $this->assertSame($reversal->id, $again->id);
        $this->assertSame($tx->id, $reversal->reversal_of_id);
        $this->assertSame('0', $this->onHand($item, $this->rack));
        $this->assertSame(2, LedgerEntry::count()); // original kept, reversal added

        $this->expectException(ValidationException::class);
        $this->engine()->reverse($reversal, $this->admin->id, 'nope');
    }

    public function test_transfer_moves_value_at_moving_average_cost(): void
    {
        $item = $this->item('RM-VAL');
        $this->receive($item, $this->rack, '10', '10');
        $this->receive($item, $this->rack, '10', '20'); // avg 15

        $this->engine()->post('transfer', [
            new Movement($item->id, $this->rack->id, 'available', '-4', null, null, null, 'p1'),
            new Movement($item->id, $this->rack2->id, 'available', '4', null, null, null, 'p1'),
        ], new PostingContext('val-1', $this->admin->id));

        $balances = Balance::query()->where('item_id', $item->id)->get()->keyBy('location_id');
        $this->assertSame('240', Decimal::normalize((string) $balances[$this->rack->id]->value));
        $this->assertSame('60', Decimal::normalize((string) $balances[$this->rack2->id]->value));
    }

    public function test_unknown_cost_is_never_invented(): void
    {
        $item = $this->item('RM-NOCOST');
        $this->receive($item, $this->rack, '10', '10');
        $this->receive($item, $this->rack, '5'); // no cost → valuation unknown

        $balance = Balance::query()->where('item_id', $item->id)->sole();
        $this->assertNull($balance->value);
        $this->assertSame('15', Decimal::normalize((string) $balance->quantity));
    }

    public function test_reserved_stock_cannot_be_consumed_by_other_postings(): void
    {
        $item = $this->item('RM-RES');
        $this->receive($item, $this->rack, '10');
        Reservation::create([
            'reservation_number' => 'RSV-T1', 'factory_id' => $this->factory->id, 'warehouse_id' => $this->store->id, 'location_id' => $this->rack->id,
            'item_id' => $item->id, 'quantity' => 7, 'uom_id' => $item->base_uom_id, 'conversion_factor' => 1, 'base_quantity' => 7,
            'released_base_quantity' => 0, 'base_uom_id' => $item->base_uom_id, 'reference_type' => 'manual', 'status' => Reservation::ACTIVE,
            'reserved_by' => $this->admin->id,
        ]);

        $summary = app(StockQuery::class)->summary($item->id, $this->store->id);
        $this->assertSame('3', $summary['available_to_promise']);

        $this->engine()->post('test', [new Movement($item->id, $this->rack->id, 'available', '-3')], new PostingContext('res-ok', $this->admin->id));
        $this->expectException(ValidationException::class);
        $this->engine()->post('test', [new Movement($item->id, $this->rack->id, 'available', '-1')], new PostingContext('res-fail', $this->admin->id));
    }

    public function test_running_stock_count_locks_locations(): void
    {
        $item = $this->item('RM-LOCK');
        $this->receive($item, $this->rack, '4');
        $session = StockCountSession::create([
            'count_number' => 'CNT-T1', 'factory_id' => $this->factory->id, 'warehouse_id' => $this->store->id,
            'location_ids' => [$this->rack->id], 'status' => 'counting', 'lock_locations' => true, 'cutoff_at' => now(),
        ]);

        try {
            $this->receive($item, $this->rack, '1');
            $this->fail('Locked location accepted a posting.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('locked by a running stock count', $e->getMessage());
        }
        $this->receive($item, $this->rack2, '1'); // other locations unaffected

        $session->forceFill(['status' => 'posted'])->save();
        $this->receive($item, $this->rack, '1');
        $this->assertSame('5', $this->onHand($item, $this->rack));
    }

    public function test_inbound_posting_into_inactive_location_is_rejected(): void
    {
        $item = $this->item('RM-INACT');
        $this->rack2->forceFill(['is_active' => false])->save();

        $this->expectException(ValidationException::class);
        $this->receive($item, $this->rack2, '1');
    }

    public function test_sequential_competing_issues_cannot_oversell(): void
    {
        // True parallelism is not available in the test runner; this checks
        // that the second of two competing issues sees the first's effect.
        $item = $this->item('RM-RACE');
        $this->receive($item, $this->rack, '10');

        $this->engine()->post('issue', [new Movement($item->id, $this->rack->id, 'available', '-7')], new PostingContext('race-1', $this->admin->id));
        $this->expectException(ValidationException::class);
        try {
            $this->engine()->post('issue', [new Movement($item->id, $this->rack->id, 'available', '-7')], new PostingContext('race-2', $this->admin->id));
        } finally {
            $this->assertSame('3', $this->onHand($item, $this->rack));
        }
    }
}
