<?php

namespace Tests\Feature\Inventory;

use App\Models\Factory;
use App\Models\Inventory\InventoryDocument;
use App\Models\Role;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Warehouses are internal: users work with factories and locations only. */
class WarehouseFreeTest extends InventoryTestCase
{
    public function test_locations_are_created_per_factory_using_one_internal_storage_area(): void
    {
        $plant = Factory::factory()->create();
        $this->actingAs($this->admin);

        $first = $this->postJson($this->api('locations'), ['factory_id' => $plant->id, 'code' => 'bay-1', 'name' => 'Bay 1', 'type' => 'rack'])
            ->assertCreated()->assertJsonPath('data.factory.id', $plant->id)->json('data');
        $this->postJson($this->api('locations'), ['factory_id' => $plant->id, 'parent_id' => $first['id'], 'code' => 'BAY-1-A', 'name' => 'Shelf A', 'type' => 'shelf'])->assertCreated();
        $this->postJson($this->api('locations'), ['factory_id' => $plant->id, 'code' => 'BAY-1', 'name' => 'Duplicate', 'type' => 'rack'])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', 'This location code is already used in this factory.');

        $this->assertSame(1, Warehouse::where('factory_id', $plant->id)->count());
        $this->getJson($this->api("factories/{$plant->id}/locations/tree"))->assertOk()
            ->assertJsonPath('data.total', 2)->assertJsonPath('data.tree.0.children.0.code', 'BAY-1-A');

        // Factories that already have a warehouse reuse it.
        $this->postJson($this->api('locations'), ['factory_id' => $this->factory->id, 'code' => 'R9', 'name' => 'Rack 9', 'type' => 'rack'])->assertCreated();
        $this->assertSame($this->store->id, WarehouseLocation::where('code', 'R9')->value('warehouse_id'));
    }

    public function test_warehouse_permissions_are_hidden_from_the_matrix_and_kept_on_save(): void
    {
        $this->actingAs($this->admin);
        $keys = collect($this->getJson($this->api('permissions'))->assertOk()->json('data.modules'))->pluck('key');
        $this->assertNotContains('warehouses', $keys);
        $this->assertContains('locations', $keys);

        $role = Role::create(['name' => 'Stores Lead', 'guard_name' => 'web']);
        $role->givePermissionTo(['warehouses.view', 'locations.view']);
        $this->putJson($this->api("roles/{$role->id}/permissions"), ['permissions' => ['locations.view', 'locations.create']])->assertOk();
        $this->assertEqualsCanonicalizing(['warehouses.view', 'locations.view', 'locations.create'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_location_in_inaccessible_factory_is_refused(): void
    {
        $other = Factory::factory()->create();
        $this->actingAs($this->staff(['locations.create', 'locations.view']));

        $this->postJson($this->api('locations'), ['factory_id' => $other->id, 'code' => 'X1', 'name' => 'X', 'type' => 'rack'])
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->assertSame(0, Warehouse::where('factory_id', $other->id)->count());
    }

    public function test_documents_reservations_and_counts_work_without_a_warehouse(): void
    {
        $item = $this->item('RM-NOWH');
        $this->actingAs($this->admin);

        $grn = $this->postJson($this->api('inventory/grns'), [
            'supplier_id' => $this->supplier()->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '10', 'uom_id' => $item->base_uom_id, 'destination_location_id' => $this->rack->id]],
        ])->assertCreated()->json('data.id');
        $this->postJson($this->api("inventory/grns/{$grn}/submit"))->assertOk();
        $this->postJson($this->api("inventory/grns/{$grn}/post"))->assertOk();
        $this->assertSame('10', $this->onHand($item, $this->rack));

        // Lines from different factories are refused.
        $elsewhere = $this->location(Warehouse::create(['factory_id' => Factory::factory()->create()->id, 'code' => 'O', 'name' => 'O', 'is_active' => true]), 'O1');
        $this->postJson($this->api('inventory/adjustments'), [
            'reason_code_id' => $this->reason('COUNT-VAR')->id,
            'lines' => [
                ['item_id' => $item->id, 'quantity' => '-1', 'uom_id' => $item->base_uom_id, 'source_location_id' => $this->rack->id, 'from_status' => 'available'],
                ['item_id' => $item->id, 'quantity' => '1', 'uom_id' => $item->base_uom_id, 'source_location_id' => $elsewhere->id, 'from_status' => 'available'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.1.source_location_id');

        $adjustment = $this->postJson($this->api('inventory/adjustments'), [
            'reason_code_id' => $this->reason('COUNT-VAR')->id,
            'lines' => [['item_id' => $item->id, 'quantity' => '-1', 'uom_id' => $item->base_uom_id, 'source_location_id' => $this->rack->id, 'from_status' => 'available']],
        ])->assertCreated();
        $this->assertSame($this->store->id, InventoryDocument::find($adjustment->json('data.id'))->warehouse_id);

        $this->postJson($this->api('inventory/reservations'), [
            'item_id' => $item->id, 'factory_id' => $this->factory->id, 'quantity' => '4', 'uom_id' => $item->base_uom_id,
            'reference_type' => 'manual', 'reference_number' => 'SO-1',
        ])->assertCreated()->assertJsonPath('data.warehouse.id', $this->store->id);

        $this->postJson($this->api('inventory/stock-counts'), ['factory_id' => $this->factory->id, 'location_ids' => [$this->rack->id]])
            ->assertCreated()->assertJsonPath('data.warehouse.id', $this->store->id);
    }

    public function test_opening_stock_finds_locations_by_code_without_warehouse_column(): void
    {
        Storage::fake('local');
        $item = $this->item('RM-OPNW');
        $this->actingAs($this->admin);
        $file = UploadedFile::fake()->createWithContent('stock.csv', "Item Code,Location Code,Quantity\nRM-OPNW,R1,7\nRM-OPNW,NOPE,1\n");

        $import = $this->post($this->api('inventory/opening-stock/import'), ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->postJson($this->api("inventory/opening-stock/imports/{$import['id']}/validate"), ['mapping' => $import['mapping']])
            ->assertOk()->assertJsonPath('data.accepted_rows', 1)->assertJsonPath('data.rejected_rows', 1);
        foreach (['submit', 'approve', 'post'] as $action) {
            $this->postJson($this->api("inventory/opening-stock/imports/{$import['id']}/{$action}"))->assertOk();
        }
        $this->assertSame('7', $this->onHand($item, $this->rack));
    }

    public function test_item_default_location_sets_its_storage_area_automatically(): void
    {
        $item = $this->item('RM-DEFLOC');
        $this->actingAs($this->admin);

        $this->putJson($this->api("items/{$item->id}"), [
            'code' => $item->code, 'name' => $item->name, 'category_id' => $item->category_id, 'base_uom_id' => $item->base_uom_id,
            'item_type' => $item->item_type, 'default_location_id' => $this->rack->id,
        ])->assertOk();
        $this->assertSame($this->store->id, $item->fresh()->default_warehouse_id);
        $this->assertSame($this->rack->id, $item->fresh()->default_location_id);
    }
}
