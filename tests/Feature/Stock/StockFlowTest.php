<?php

namespace Tests\Feature\Stock;

use App\Models\Factory;
use App\Models\Stock\StockDocument;
use App\Models\Stock\StockLedgerEntry;
use App\Models\Stock\StockLevel;
use App\Models\Stock\VendorSku;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Inward, outward, opening stock, reversal and the live balance. */
class StockFlowTest extends StockTestCase
{
    public function test_live_balance_follows_the_spec_example(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('Birju Buff', [$sku]);

        $this->opening($sku, '100');
        $inward = $this->posted('inward', $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '50', 'rejected_qty' => '5', 'reference' => 'INV-77']));
        $this->assertSame('45', $inward['accepted_qty']);
        $this->posted('outward', $this->base(['sku_id' => $sku->id, 'quantity' => '20', 'reference' => 'Production']));

        $row = $this->overviewRow($sku);
        $this->assertSame(['100', '50', '5', '20', '125'], [$row['opening_stock'], $row['total_inward'], $row['total_rejected'], $row['total_outward'], $row['live_balance']]);
        $this->assertSame('45', $row['accepted_inward']);
        $this->assertSame('125', (string) (float) StockLevel::sole()->quantity);
    }

    public function test_drafts_and_cancelled_entries_do_not_affect_stock(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V1', [$sku]);
        $this->actingAs($this->admin);

        $draft = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '10']))->assertCreated()->json('data');
        $this->assertSame('draft', $draft['status']);
        $this->assertSame(['edit', 'post', 'cancel', 'delete'], $draft['allowed_actions']);
        $other = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '7']))->json('data.id');
        $this->postJson($this->api("inward/{$other}/cancel"), ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->api("inward/{$other}/post"))->assertStatus(409);

        $this->assertSame('0', $this->overviewRow($sku)['live_balance']);
        $this->assertSame(0, StockLedgerEntry::count());

        // Drafts can be edited; posted entries cannot.
        $this->putJson($this->api("inward/{$draft['id']}"), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '12']))
            ->assertOk()->assertJsonPath('data.received_qty', '12');
        $this->postJson($this->api("inward/{$draft['id']}/post"))->assertOk();
        $this->postJson($this->api("inward/{$draft['id']}/post"))->assertStatus(409); // never twice
        // Posted entries are never changed silently: an edit needs a reason (and edit-posted permission).
        $this->putJson($this->api("inward/{$draft['id']}"), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '1']))->assertJsonValidationErrors('edit_reason');
        $this->assertSame('12', $this->overviewRow($sku)['live_balance']);
    }

    public function test_inward_requires_vendor_sku_mapping_unless_overridden_with_a_reason(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('Unmapped Traders');
        $clerk = $this->staff(['inward.view', 'inward.create', 'inward.update', 'inward.post']);
        $this->actingAs($clerk);

        $doc = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '5']))
            ->assertCreated()->assertJsonPath('data.vendor_mapped', false)->json('data');
        $this->postJson($this->api("inward/{$doc['id']}/post"))->assertUnprocessable()
            ->assertJsonPath('errors.vendor_id.0', "Unmapped Traders is not mapped to SKU {$sku->sku}. Add the mapping in Vendor Master first.");

        $manager = $this->staff(['inward.view', 'inward.post', 'inward.override-vendor-check']);
        $this->actingAs($manager);
        $this->postJson($this->api("inward/{$doc['id']}/post"))->assertUnprocessable()->assertJsonValidationErrors('vendor_check_reason');
        $this->postJson($this->api("inward/{$doc['id']}/post"), ['vendor_check_reason' => 'New supplier, mapping pending'])
            ->assertOk()->assertJsonPath('data.status', 'posted')->assertJsonPath('data.vendor_mapped', false)
            ->assertJsonPath('data.vendor_check_reason', 'New supplier, mapping pending');

        // Mapped vendors post without any extra step.
        VendorSku::create(['vendor_id' => $vendor->id, 'sku_id' => $sku->id, 'is_active' => true]);
        $this->actingAs($clerk);
        $id = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '3']))->json('data.id');
        $this->postJson($this->api("inward/{$id}/post"))->assertOk()->assertJsonPath('data.vendor_mapped', true);
    }

    public function test_rejected_quantity_is_validated_and_counted_once(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V', [$sku]);
        $this->actingAs($this->admin);
        $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '5', 'rejected_qty' => '6']))
            ->assertUnprocessable()->assertJsonValidationErrors('rejected_qty');
        $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors('received_qty');

        $this->posted('inward', $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '10', 'rejected_qty' => '10']));
        $row = $this->overviewRow($sku);
        $this->assertSame(['10', '10', '0'], [$row['total_inward'], $row['total_rejected'], $row['live_balance']]);
    }

    public function test_outward_cannot_exceed_available_stock(): void
    {
        $sku = $this->sku();
        $this->opening($sku, '30');
        $this->actingAs($this->admin);

        $this->getJson($this->api("stock/availability?sku_id={$sku->id}&location_id={$this->rack->id}"))->assertOk()->assertJsonPath('data.available', '30');
        $first = $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '20']))->json('data.id');
        $second = $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '20']))->json('data.id');

        // Two issues competing for the same stock: the second sees the first and is refused.
        $this->postJson($this->api("outward/{$first}/post"))->assertOk();
        $this->postJson($this->api("outward/{$second}/post"))->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', "Insufficient stock: only 10 of {$sku->sku} is available at RACK-A.");
        $this->assertSame('draft', StockDocument::find($second)->status);
        $this->assertSame('10', $this->overviewRow($sku)['live_balance']);

        // Stock at another location does not count.
        $this->posted('opening-stock', $this->base(['sku_id' => $sku->id, 'quantity' => '50', 'location_id' => $this->shelf->id]));
        $this->postJson($this->api("outward/{$second}/post"))->assertUnprocessable();
    }

    public function test_reversal_restores_stock_and_keeps_history(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V', [$sku]);
        $this->opening($sku, '10');
        $inward = $this->posted('inward', $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '8', 'rejected_qty' => '2']));
        $outward = $this->posted('outward', $this->base(['sku_id' => $sku->id, 'quantity' => '15']));
        $this->assertSame('1', $this->overviewRow($sku)['live_balance']);

        // Reversing the inward would leave −5: refused because the stock was already issued.
        $this->postJson($this->api("inward/{$inward['id']}/reverse"), ['reason' => 'Wrong SKU'])->assertUnprocessable();
        $this->postJson($this->api("outward/{$outward['id']}/reverse"), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->api("outward/{$outward['id']}/reverse"), ['reason' => 'Issued by mistake'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->postJson($this->api("inward/{$inward['id']}/reverse"), ['reason' => 'Wrong SKU'])->assertOk();
        $this->postJson($this->api("inward/{$inward['id']}/reverse"), ['reason' => 'again'])->assertStatus(409);

        $row = $this->overviewRow($sku);
        $this->assertSame(['10', '0', '0', '0', '10'], [$row['opening_stock'], $row['total_inward'], $row['total_rejected'], $row['total_outward'], $row['live_balance']]);
        $this->assertSame(5, StockLedgerEntry::count()); // 3 postings + 2 reversals, nothing deleted

        $history = $this->getJson($this->api("stock/history/{$sku->id}"))->assertOk()->json('data');
        $this->assertCount(5, $history);
        $this->assertTrue($history[0]['is_reversal']);
    }

    public function test_opening_stock_is_set_once_per_sku_and_location(): void
    {
        $sku = $this->sku();
        $first = $this->opening($sku, '40');
        $this->actingAs($this->admin);
        $id = $this->postJson($this->api('opening-stock'), $this->base(['sku_id' => $sku->id, 'quantity' => '5']))->json('data.id');
        $this->postJson($this->api("opening-stock/{$id}/post"))->assertUnprocessable()->assertJsonValidationErrors('quantity');

        // After reversing the wrong opening entry, it can be set again.
        $this->postJson($this->api("opening-stock/{$first['id']}/reverse"), ['reason' => 'Counted wrongly'])->assertOk();
        $this->postJson($this->api("opening-stock/{$id}/post"))->assertOk();
        $this->assertSame('5', $this->overviewRow($sku)['opening_stock']);
    }

    public function test_ledger_entries_cannot_be_edited_or_deleted(): void
    {
        $sku = $this->sku();
        $this->opening($sku, '3');
        $entry = StockLedgerEntry::sole();

        $this->expectException(LogicException::class);
        $entry->update(['qty_in' => 300]);
    }

    public function test_stock_is_kept_per_location_without_double_counting_children(): void
    {
        $sku = $this->sku();
        $this->opening($sku, '10', $this->rack);
        $this->opening($sku, '4', $this->shelf); // shelf is inside rack

        $this->assertSame('14', $this->overviewRow($sku)['live_balance']);
        $this->assertSame('14', $this->overviewRow($sku, ['location_id' => $this->rack->id])['live_balance']); // includes its shelf
        $this->assertSame('4', $this->overviewRow($sku, ['location_id' => $this->shelf->id])['live_balance']);
    }

    public function test_overview_statuses_zero_stock_filters_and_export(): void
    {
        $low = $this->sku('ET-LOW', ['reorder_level' => '10']);
        $ok = $this->sku('ET-OK', ['reorder_level' => '10', 'material' => '304']);
        $unset = $this->sku('ET-UNSET');
        $empty = $this->sku('ET-EMPTY', ['category' => 'Strip']);
        $this->opening($low, '10');
        $this->opening($ok, '11');
        $this->opening($unset, '3');

        $this->assertSame(['low', 'LOW STOCK'], [$this->overviewRow($low)['status'], $this->overviewRow($low)['status_label']]);
        $this->assertSame('ok', $this->overviewRow($ok)['status']);
        $this->assertSame(['not_set', 'Reorder level not set'], [$this->overviewRow($unset)['status'], $this->overviewRow($unset)['status_label']]);
        $this->assertSame(['0', 'low'], [$this->overviewRow($empty)['live_balance'], $this->overviewRow($empty)['status']]); // zero stock is listed

        $this->actingAs($this->admin);
        $this->assertSame(['ET-EMPTY', 'ET-LOW'], array_column($this->getJson($this->api('stock/overview?status=low'))->json('data'), 'sku'));
        $this->assertSame(['ET-OK'], array_column($this->getJson($this->api('stock/overview?material=304'))->json('data'), 'sku'));
        $this->assertSame(['ET-EMPTY'], array_column($this->getJson($this->api('stock/overview?category=Strip'))->json('data'), 'sku'));
        $this->assertSame(['ET-OK'], array_column($this->getJson($this->api('stock/overview?search=et-ok'))->json('data'), 'sku'));
        $sorted = array_column($this->getJson($this->api('stock/overview?sort=live_balance&direction=desc'))->json('data'), 'sku');
        $this->assertSame('ET-OK', $sorted[0]);
        $this->get($this->api('stock/overview?export=csv'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_inactive_sku_vendor_or_location_cannot_be_used_for_new_entries(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V', [$sku]);
        $this->actingAs($this->admin);
        $draft = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '1']))->json('data.id');

        $sku->update(['is_active' => false]);
        $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '1']))->assertUnprocessable()->assertJsonValidationErrors('sku_id');
        $this->postJson($this->api("inward/{$draft}/post"))->assertUnprocessable()->assertJsonValidationErrors('sku_id'); // re-checked at posting
        $sku->update(['is_active' => true]);

        $vendor->update(['is_active' => false]);
        $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '1']))->assertJsonValidationErrors('vendor_id');
        $vendor->update(['is_active' => true]);

        $this->rack->update(['is_active' => false]);
        $this->postJson($this->api('opening-stock'), $this->base(['sku_id' => $sku->id, 'quantity' => '1']))->assertJsonValidationErrors('location_id');
        // A location of another factory is refused.
        $other = Factory::factory()->create();
        $this->postJson($this->api('opening-stock'), $this->base(['sku_id' => $sku->id, 'quantity' => '1', 'factory_id' => $other->id, 'location_id' => $this->shelf->id]))
            ->assertJsonValidationErrors('location_id');
    }

    public function test_permissions_and_factory_scope_are_enforced(): void
    {
        $sku = $this->sku();
        $this->opening($sku, '5');
        $viewer = $this->staff(['outward.view', 'stock-overview.view']);
        $this->actingAs($viewer);

        $this->getJson($this->api('outward'))->assertOk();
        $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '1']))->assertForbidden();
        $this->getJson($this->api('inward'))->assertForbidden();
        $this->getJson($this->api('stock/overview?export=csv'))->assertForbidden();
        $this->postJson($this->api('skus'), ['sku' => 'X', 'product_name' => 'Y'])->assertForbidden();

        $this->actingAs($this->admin);
        $id = $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '1']))->json('data.id');
        $this->actingAs($viewer);
        $this->postJson($this->api("outward/{$id}/post"))->assertForbidden();
        $this->assertSame('draft', StockDocument::find($id)->status);

        // Another factory's user sees neither the entry nor its stock.
        $outsider = $this->staff(['outward.view', 'outward.post', 'stock-overview.view'], Factory::factory()->create());
        $this->actingAs($outsider);
        $this->getJson($this->api("outward/{$id}"))->assertNotFound();
        $this->postJson($this->api("outward/{$id}/post"))->assertNotFound();
        $this->assertSame([], $this->getJson($this->api('outward'))->json('data'));
        $row = collect($this->getJson($this->api('stock/overview'))->json('data'))->firstWhere('sku', $sku->sku);
        $this->assertSame('0', $row['live_balance']);
        $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '1']))->assertForbidden();
    }

    public function test_document_numbers_are_sequential_and_lists_filter(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('Jay Khodiyar', [$sku]);
        $this->actingAs($this->admin);
        $a = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '1', 'job_no' => 'J-9']))->json('data.number');
        $b = $this->postJson($this->api('inward'), $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '2']))->json('data.number');
        $this->assertSame(['INW-000001', 'INW-000002'], [$a, $b]);

        $this->assertCount(1, $this->getJson($this->api('inward?search=j-9'))->json('data'));
        $this->assertCount(2, $this->getJson($this->api("inward?vendor_id={$vendor->id}&date_from=".now()->toDateString()))->json('data'));
        $this->assertCount(0, $this->getJson($this->api('inward?status=posted'))->json('data'));
        $this->get($this->api('inward?export=csv'))->assertOk();
        $this->assertSame(2, DB::table('activity_logs')->where('action', 'stock.inward.created')->count());
    }
}
