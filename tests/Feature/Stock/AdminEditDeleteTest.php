<?php

namespace Tests\Feature\Stock;

use App\Models\Stock\Sku;
use App\Models\Stock\StockDocument;
use App\Models\Stock\StockLedgerEntry;
use App\Models\Stock\Vendor;
use App\Models\Stock\VendorSku;
use Illuminate\Support\Facades\DB;

/** Admin edit / update / delete across the inventory module, with audit. */
class AdminEditDeleteTest extends StockTestCase
{
    public function test_admin_edits_a_posted_inward_and_stock_is_recalculated(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V', [$sku]);
        $inward = $this->posted('inward', $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '50', 'rejected_qty' => '5']));
        $this->assertSame('45', $this->overviewRow($sku)['live_balance']);
        $this->assertContains('edit_posted', $inward['allowed_actions']);

        $payload = $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '40', 'rejected_qty' => '2']);
        $this->putJson($this->api("inward/{$inward['id']}"), $payload)->assertUnprocessable()->assertJsonValidationErrors('edit_reason');
        $this->putJson($this->api("inward/{$inward['id']}"), $payload + ['edit_reason' => 'Counted wrongly at the gate'])->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.received_qty', '40')->assertJsonPath('data.edit_count', 1);

        $row = $this->overviewRow($sku);
        $this->assertSame(['40', '2', '38'], [$row['total_inward'], $row['total_rejected'], $row['live_balance']]);
        $this->assertSame(3, StockLedgerEntry::count()); // post + correction (out/in), nothing rewritten
        $log = DB::table('activity_logs')->where('action', 'stock.inward.edited')->sole();
        $this->assertStringContainsString('Counted wrongly', $log->description);
        $this->assertStringContainsString('"received_qty":{"from":"50","to":"40"}', $log->metadata);
    }

    public function test_corrections_never_make_stock_negative_and_can_move_location(): void
    {
        $sku = $this->sku();
        $this->opening($sku, '30');
        $outward = $this->posted('outward', $this->base(['sku_id' => $sku->id, 'quantity' => '20']));

        $this->putJson($this->api("outward/{$outward['id']}"), $this->base(['sku_id' => $sku->id, 'quantity' => '31', 'edit_reason' => 'more issued']))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame('10', $this->overviewRow($sku)['live_balance']);

        $this->putJson($this->api("outward/{$outward['id']}"), $this->base(['sku_id' => $sku->id, 'quantity' => '25', 'edit_reason' => 'Actual issue was 25']))->assertOk();
        $this->assertSame('5', $this->overviewRow($sku)['live_balance']);

        // Moving an opening entry to another location moves its stock.
        $opening = StockDocument::where('type', 'opening')->sole();
        $this->putJson($this->api("opening-stock/{$opening->id}"), $this->base(['sku_id' => $sku->id, 'quantity' => '30', 'location_id' => $this->shelf->id, 'edit_reason' => 'Wrong rack']))
            ->assertUnprocessable(); // rack would go to −25 because the outward was taken from it
        $this->assertSame('5', $this->overviewRow($sku, ['location_id' => $this->rack->id])['live_balance']);
    }

    public function test_admin_deletes_entries_and_stock_follows(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V', [$sku]);
        $this->opening($sku, '10');
        $inward = $this->posted('inward', $this->base(['sku_id' => $sku->id, 'vendor_id' => $vendor->id, 'received_qty' => '5']));
        $draft = $this->postJson($this->api('outward'), $this->base(['sku_id' => $sku->id, 'quantity' => '1']))->json('data.id');

        $this->deleteJson($this->api("outward/{$draft}"))->assertOk();
        $this->assertNull(StockDocument::withTrashed()->find($draft)); // drafts are removed

        $this->deleteJson($this->api("inward/{$inward['id']}"))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->deleteJson($this->api("inward/{$inward['id']}"), ['reason' => 'Duplicate of INW-000099'])->assertOk()
            ->assertJsonPath('message', "{$inward['number']} deleted. Stock recalculated.");
        $this->assertSame('10', $this->overviewRow($sku)['live_balance']);
        $this->assertSame([], $this->getJson($this->api('inward'))->json('data'));
        $deleted = $this->getJson($this->api('inward?status=deleted'))->assertOk()->json('data');
        $this->assertSame([$inward['number']], array_column($deleted, 'number'));
        $this->getJson($this->api("inward/{$inward['id']}"))->assertOk()->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.delete_reason', 'Duplicate of INW-000099')->assertJsonPath('data.allowed_actions', []);
        $this->assertCount(3, $this->getJson($this->api("stock/history/{$sku->id}"))->json('data')); // history keeps the trail
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'stock.inward.deleted')->count());
    }

    public function test_deleting_stock_that_was_already_issued_is_refused(): void
    {
        $sku = $this->sku();
        $opening = $this->opening($sku, '10');
        $this->posted('outward', $this->base(['sku_id' => $sku->id, 'quantity' => '8']));

        $this->deleteJson($this->api("opening-stock/{$opening['id']}"), ['reason' => 'test'])->assertUnprocessable();
        $this->assertNotNull(StockDocument::find($opening['id']));
        $this->assertSame('2', $this->overviewRow($sku)['live_balance']);
    }

    public function test_sku_and_vendor_delete_only_when_unused(): void
    {
        $unused = $this->sku('ET-UNUSED');
        $used = $this->sku('ET-USED');
        $vendor = $this->vendor('Unused Vendor', [$unused]);
        $busy = $this->vendor('Busy Vendor', [$used]);
        $this->posted('inward', $this->base(['sku_id' => $used->id, 'vendor_id' => $busy->id, 'received_qty' => '1']));

        $this->deleteJson($this->api("skus/{$unused->id}"))->assertOk();
        $this->assertNull(Sku::find($unused->id));
        $this->assertSame(0, VendorSku::where('sku_id', $unused->id)->count());
        $this->deleteJson($this->api("skus/{$used->id}"))->assertStatus(409)
            ->assertJsonPath('message', 'SKU ET-USED cannot be deleted because 1 stock entry uses it (INW-000001). Stock history must stay complete — deactivate the SKU instead.');

        $this->deleteJson($this->api("vendors/{$vendor->id}"))->assertOk();
        $this->assertNull(Vendor::find($vendor->id));
        $this->deleteJson($this->api("vendors/{$busy->id}"))->assertStatus(409);
        $this->assertSame(2, DB::table('activity_logs')->whereIn('action', ['sku.deleted', 'vendor.deleted'])->count());
    }

    public function test_only_permitted_users_can_edit_posted_or_delete(): void
    {
        $sku = $this->sku();
        $posted = $this->opening($sku, '5');
        $keeper = $this->createUserWithRole('Store Keeper');
        $keeper->forceFill(['factory_id' => $this->factory->id])->save();
        $this->actingAs($keeper->fresh());

        $this->putJson($this->api("opening-stock/{$posted['id']}"), $this->base(['sku_id' => $sku->id, 'quantity' => '6', 'edit_reason' => 'x']))->assertForbidden();
        $this->deleteJson($this->api("opening-stock/{$posted['id']}"), ['reason' => 'x'])->assertForbidden();
        $this->deleteJson($this->api("skus/{$sku->id}"))->assertForbidden();
        $this->assertNotContains('delete', $this->getJson($this->api("opening-stock/{$posted['id']}"))->json('data.allowed_actions'));
        $this->assertSame('5', $this->overviewRow($sku)['live_balance']);
    }
}
