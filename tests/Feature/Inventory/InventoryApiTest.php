<?php

namespace Tests\Feature\Inventory;

use App\Models\Factory;
use App\Models\Inventory\InventoryTransaction;
use App\Models\Inventory\LedgerEntry;
use App\Models\MasterData\ItemCategory;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReports;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class InventoryApiTest extends InventoryTestCase
{
    private function grnPayload($item, string $qty, array $line = []): array
    {
        return [
            'warehouse_id' => $this->store->id,
            'supplier_id' => $this->supplier()->id,
            'supplier_invoice_number' => 'CH-11',
            'lines' => [['item_id' => $item->id, 'quantity' => $qty, 'uom_id' => $item->base_uom_id, 'destination_location_id' => $this->rack->id, ...$line]],
        ];
    }

    public function test_grn_api_flow_and_duplicate_post_is_idempotent(): void
    {
        $item = $this->item('RM-API');
        $this->actingAs($this->admin);

        $id = $this->postJson($this->api('inventory/grns'), $this->grnPayload($item, '12.5', ['unit_cost' => '8']))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->postJson($this->api("inventory/grns/{$id}/submit"))->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson($this->api("inventory/grns/{$id}/post"))->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson($this->api("inventory/grns/{$id}/post"))->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, InventoryTransaction::where('source_document_type', 'grn')->count());
        $this->assertSame('12.5', $this->onHand($item, $this->rack));

        $this->getJson($this->api("inventory/grns/{$id}"))->assertOk()
            ->assertJsonCount(3, 'data.events')
            ->assertJsonPath('data.lines.0.base_quantity', '12.5')
            ->assertJsonPath('data.allowed_actions', ['close']);

        // Illegal state change is refused.
        $this->postJson($this->api("inventory/grns/{$id}/submit"))->assertStatus(409);
        // Posted documents cannot be edited.
        $this->putJson($this->api("inventory/grns/{$id}"), $this->grnPayload($item, '1'))->assertStatus(409);
    }

    public function test_unauthorized_users_are_blocked(): void
    {
        $item = $this->item('RM-AUTH');
        $viewer = $this->staff(['inventory.grn.view', 'inventory.stock.view']);
        $this->actingAs($viewer);

        $this->getJson($this->api('inventory/grns'))->assertOk();
        $this->postJson($this->api('inventory/grns'), $this->grnPayload($item, '1'))->assertForbidden();
        $this->getJson($this->api('inventory/ledger'))->assertForbidden();
        $this->getJson($this->api('inventory/adjustments'))->assertForbidden();
        $this->getJson($this->api('inventory/stock?export=xlsx'))->assertForbidden();
        $this->postJson($this->api('inventory/reservations'), [])->assertForbidden();
        $this->postJson($this->api('inventory/opening-stock/import'), [])->assertForbidden();

        $this->actingAs($this->admin);
        $id = $this->postJson($this->api('inventory/grns'), $this->grnPayload($item, '1'))->json('data.id');
        $this->postJson($this->api("inventory/grns/{$id}/submit"))->assertOk();

        $this->actingAs($viewer);
        $this->postJson($this->api("inventory/grns/{$id}/post"))->assertForbidden();
        $this->assertSame(0, LedgerEntry::count());

        // A user of another factory cannot see the document at all.
        $other = $this->createUserWithPermissions(['inventory.grn.view']);
        $other->forceFill(['factory_id' => Factory::factory()->create()->id])->save();
        $this->actingAs($other->fresh());
        $this->getJson($this->api("inventory/grns/{$id}"))->assertNotFound();
        $this->getJson($this->api('inventory/grns'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_lookups_are_scoped_to_the_users_factory(): void
    {
        $otherFactory = Factory::factory()->create();
        $otherStore = Warehouse::create(['factory_id' => $otherFactory->id, 'code' => 'OTH', 'name' => 'Other Store', 'is_active' => true]);
        $this->location($otherStore, 'X1');
        $keeper = $this->staff(['inventory.transfers.view']);
        $this->actingAs($keeper);

        $structure = $this->getJson($this->api('inventory/lookups/structure'))->assertOk();
        $this->assertSame(['RMS', 'SHOP'], array_column($structure->json('data.warehouses'), 'code'));
        $codes = array_column($this->getJson($this->api('inventory/lookups/locations'))->json('data'), 'code');
        $this->assertNotContains('X1', $codes);
        $this->assertSame(['WIP1'], array_column($this->getJson($this->api('inventory/lookups/locations?purpose=production'))->json('data'), 'code'));
        $this->getJson($this->api('inventory/lookups/suppliers'))->assertForbidden();
    }

    public function test_cross_dimension_uom_is_rejected(): void
    {
        $item = $this->item('RM-DIM', 'KG');
        $this->actingAs($this->admin);

        $this->postJson($this->api('inventory/grns'), [
            ...$this->grnPayload($item, '5'),
            'lines' => [['item_id' => $item->id, 'quantity' => '5', 'uom_id' => $this->uom('MTR')->id, 'destination_location_id' => $this->rack->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.uom_id');
    }

    public function test_stock_views_wip_finished_goods_and_scrap_are_isolated(): void
    {
        $rm = $this->item('RM-VIEW');
        $fg = $this->item('FG-VIEW', 'PCS', ['category_id' => ItemCategory::where('code', 'FG')->sole()->id, 'is_manufactured' => true]);
        $this->receive($rm, $this->rack, '30');
        $this->receive($rm, $this->wip, '4');
        $this->receive($rm, $this->rack2, '2', null, 'scrap');
        $this->receive($fg, $this->rack, '50');
        $this->actingAs($this->admin);

        $this->getJson($this->api('inventory/wip'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location.code', 'WIP1')->assertJsonPath('data.0.quantity', '4');
        $this->getJson($this->api('inventory/finished-goods'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item.code', 'FG-VIEW')->assertJsonPath('data.0.uom.code', 'PCS');
        $this->getJson($this->api('inventory/scrap-stock'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_status', 'scrap');

        // Scrap is not available to promise.
        $card = $this->getJson($this->api("inventory/stock/{$rm->id}"))->assertOk();
        $store = collect($card->json('data.warehouses'))->firstWhere('warehouse.code', 'RMS');
        $this->assertSame('30', $store['available_to_promise']);
        $this->assertSame('2', $store['by_status']['scrap']);
    }

    public function test_rejected_stock_cannot_be_reserved_and_release_restores_availability(): void
    {
        $item = $this->item('RM-REJ');
        $rejectArea = $this->location($this->store, 'REJ', ['stock_purpose' => 'rejected']);
        $this->receive($item, $this->rack, '5');
        $this->receive($item, $this->rack, '7', null, 'rejected');
        $this->receive($item, $rejectArea, '9'); // "available" status, but in a rejected-stock location
        $this->actingAs($this->admin);
        $base = ['item_id' => $item->id, 'warehouse_id' => $this->store->id, 'uom_id' => $item->base_uom_id, 'reference_type' => 'manual', 'reference_number' => 'SO-TEMP-1'];

        $this->postJson($this->api('inventory/reservations'), [...$base, 'quantity' => '6'])->assertUnprocessable();
        $this->postJson($this->api('inventory/reservations'), [...$base, 'quantity' => '1', 'location_id' => $rejectArea->id])->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $id = $this->postJson($this->api('inventory/reservations'), [...$base, 'quantity' => '5'])->assertCreated()->json('data.id');

        $this->getJson($this->api('inventory/stock/availability?item_id='.$item->id.'&location_id='.$this->rack->id))
            ->assertOk()->assertJsonPath('data.warehouse_available_to_promise', '0');
        $this->postJson($this->api("inventory/reservations/{$id}/release"), ['base_quantity' => '2'])->assertOk()->assertJsonPath('data.status', 'partially_released');
        $this->getJson($this->api('inventory/stock/availability?item_id='.$item->id.'&location_id='.$this->rack->id))
            ->assertJsonPath('data.warehouse_available_to_promise', '2');
    }

    public function test_reports_reconcile_with_the_ledger(): void
    {
        $a = $this->item('RM-R1');
        $b = $this->item('RM-R2', 'PCS', ['reorder_level' => '10']);
        $this->receive($a, $this->rack, '10', '4');
        $this->receive($a, $this->rack2, '5');   // no cost
        $this->receive($b, $this->rack, '3', '2');
        $this->actingAs($this->admin);

        $reports = $this->getJson($this->api('inventory/reports'))->assertOk()->json('data');
        $this->assertCount(18, $reports);
        foreach (array_keys(InventoryReports::REPORTS) as $key) {
            $this->getJson($this->api("inventory/reports/{$key}"))->assertOk();
        }

        $current = collect($this->getJson($this->api('inventory/reports/current-stock'))->json('data'));
        $ledgerTotal = fn ($itemId) => LedgerEntry::where('item_id', $itemId)->pluck('quantity')->reduce(fn ($c, $q) => bcadd($c, (string) $q, 6), '0');
        $this->assertSame(Decimal::normalize($ledgerTotal($a->id)), Decimal::normalize($current->where('item_code', 'RM-R1')->reduce(fn ($c, $r) => bcadd($c, $r['quantity'], 6), '0')));

        $valuation = $this->getJson($this->api('inventory/reports/inventory-valuation'))->assertOk();
        $this->assertSame('46', $valuation->json('summary.known_value_total')); // 10×4 + 3×2; the 5 without cost is not valued
        $this->assertSame(1, $valuation->json('summary.balances_without_cost'));

        $this->getJson($this->api('inventory/reports/low-stock'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_code', 'RM-R2');

        $dashboard = $this->getJson($this->api('inventory/dashboard'))->assertOk();
        $byUom = collect($dashboard->json('data.stock_by_uom'))->keyBy('uom');
        $this->assertSame('15', $byUom['KG']['quantity']);
        $this->assertSame('3', $byUom['PCS']['quantity']); // never added to KG
        $this->assertSame('46', $dashboard->json('data.valuation.known_value'));
        $this->assertSame(1, $dashboard->json('data.low_stock.count'));

        $ledger = $this->getJson($this->api('inventory/ledger?item_id='.$a->id.'&order=asc'))->assertOk();
        $this->assertSame(['10', '5'], array_column($ledger->json('data'), 'running_balance'));
        $this->getJson($this->api('inventory/ledger?export=csv'))->assertOk();
    }

    public function test_opening_stock_api_wizard(): void
    {
        Storage::fake('local');
        $item = $this->item('RM-WIZ');
        $this->actingAs($this->admin);

        $this->get($this->api('inventory/opening-stock/template'))->assertOk();
        $file = UploadedFile::fake()->createWithContent('stock.csv', "Item Code,Item Name,Warehouse Code,Location Code,Quantity\nRM-WIZ,Bar,RMS,R1,15\nUNKNOWN,,RMS,R1,1\n");
        $import = $this->post($this->api('inventory/opening-stock/import'), ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->postJson($this->api("inventory/opening-stock/imports/{$import['id']}/validate"), ['mapping' => $import['mapping']])
            ->assertOk()->assertJsonPath('data.accepted_rows', 1)->assertJsonPath('data.rejected_rows', 1);
        $this->getJson($this->api("inventory/opening-stock/imports/{$import['id']}?row_status=rejected"))->assertOk()
            ->assertJsonPath('data.rows.0.errors.0', 'Item UNKNOWN does not exist.');
        $this->get($this->api("inventory/opening-stock/imports/{$import['id']}/errors"))->assertOk();

        foreach (['submit', 'approve', 'post'] as $action) {
            $this->postJson($this->api("inventory/opening-stock/imports/{$import['id']}/{$action}"))->assertOk();
        }
        $this->postJson($this->api("inventory/opening-stock/imports/{$import['id']}/post"))->assertStatus(409); // no double posting
        $this->assertSame('15', $this->onHand($item, $this->rack));
    }

    public function test_stock_count_api_and_reconciliation_export(): void
    {
        $item = $this->item('RM-CAPI');
        $this->receive($item, $this->rack, '9');
        $counter = $this->staff(['inventory.counts.view', 'inventory.counts.create', 'inventory.counts.submit']);
        $approver = $this->staff(['inventory.counts.view', 'inventory.counts.approve', 'inventory.counts.post', 'inventory.reports.export']);

        $this->actingAs($counter);
        $id = $this->postJson($this->api('inventory/stock-counts'), ['warehouse_id' => $this->store->id, 'location_ids' => [$this->rack->id]])->assertCreated()->json('data.id');
        $this->postJson($this->api("inventory/stock-counts/{$id}/start"))->assertOk()->assertJsonPath('data.status', 'counting');
        $lineId = $this->getJson($this->api("inventory/stock-counts/{$id}"))->json('data.lines.0.id');
        $this->putJson($this->api("inventory/stock-counts/{$id}/counts"), ['entries' => [['line_id' => $lineId, 'counted_quantity' => '11']]])->assertOk();
        $this->postJson($this->api("inventory/stock-counts/{$id}/submit"))->assertOk();
        $this->postJson($this->api("inventory/stock-counts/{$id}/approve"))->assertForbidden();

        $this->actingAs($approver);
        $this->getJson($this->api("inventory/stock-counts/{$id}"))->assertJsonPath('data.lines.0.variance', '2');
        $this->postJson($this->api("inventory/stock-counts/{$id}/approve"))->assertOk();
        $this->postJson($this->api("inventory/stock-counts/{$id}/post"))->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson($this->api("inventory/stock-counts/{$id}/post"))->assertStatus(409);
        $this->get($this->api("inventory/stock-counts/{$id}?export=xlsx"))->assertOk();
        $this->assertSame('11', $this->onHand($item, $this->rack));
    }
}
