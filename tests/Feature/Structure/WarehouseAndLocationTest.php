<?php

namespace Tests\Feature\Structure;

use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseAndLocationTest extends StructureTestCase
{
    public function test_admin_can_create_a_warehouse_independent_of_departments(): void
    {
        $factory = $this->makeFactory();
        $manager = User::factory()->create();

        $this->actingAs($this->admin)->postJson($this->api('warehouses'), [
            'factory_id' => $factory->id, 'code' => 'rms', 'name' => 'Raw Material Store', 'manager_id' => $manager->id,
        ])->assertCreated()->assertJsonPath('data.code', 'RMS')->assertJsonPath('data.department', null);

        $this->assertDatabaseHas('activity_logs', ['action' => 'warehouse.created']);
    }

    public function test_warehouse_cannot_reference_a_department_from_another_factory(): void
    {
        $factory = $this->makeFactory();
        $foreignDept = $this->makeDepartment($this->makeFactory());
        $ownDept = $this->makeDepartment($factory);

        $this->actingAs($this->admin)->postJson($this->api('warehouses'), [
            'factory_id' => $factory->id, 'department_id' => $foreignDept->id, 'code' => 'W1', 'name' => 'W1',
        ])->assertJsonValidationErrors(['department_id' => 'same factory']);

        $this->actingAs($this->admin)->postJson($this->api('warehouses'), [
            'factory_id' => $factory->id, 'department_id' => $ownDept->id, 'code' => 'W1', 'name' => 'W1',
        ])->assertCreated()->assertJsonPath('data.department.id', $ownDept->id);
    }

    public function test_warehouse_manager_must_be_active(): void
    {
        $factory = $this->makeFactory();
        $inactive = User::factory()->inactive()->create();

        $this->actingAs($this->admin)->postJson($this->api('warehouses'), [
            'factory_id' => $factory->id, 'code' => 'W1', 'name' => 'W1', 'manager_id' => $inactive->id,
        ])->assertJsonValidationErrors('manager_id');
    }

    public function test_duplicate_warehouse_codes_are_rejected_within_a_factory(): void
    {
        $a = $this->makeFactory();
        $this->makeWarehouse($a, ['code' => 'FGS']);

        $this->actingAs($this->admin)->postJson($this->api('warehouses'), ['factory_id' => $a->id, 'code' => 'fgs', 'name' => 'Finished'])
            ->assertJsonValidationErrors(['code' => 'already used']);

        $this->actingAs($this->admin)->postJson($this->api('warehouses'), ['factory_id' => $this->makeFactory()->id, 'code' => 'FGS', 'name' => 'Finished'])
            ->assertCreated();
    }

    public function test_location_cannot_reference_an_invalid_warehouse(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('locations'), ['warehouse_id' => 9999, 'code' => 'A', 'name' => 'A', 'type' => 'rack'])
            ->assertJsonValidationErrors('warehouse_id');

        $inactive = $this->makeWarehouse($this->makeFactory(), ['is_active' => false]);
        $this->actingAs($this->admin)->postJson($this->api('locations'), ['warehouse_id' => $inactive->id, 'code' => 'A', 'name' => 'A', 'type' => 'rack'])
            ->assertJsonValidationErrors('warehouse_id');
    }

    public function test_child_location_cannot_belong_to_another_warehouse(): void
    {
        $factory = $this->makeFactory();
        $w1 = $this->makeWarehouse($factory);
        $w2 = $this->makeWarehouse($factory);
        $rackInW2 = $this->makeLocation($w2);

        $this->actingAs($this->admin)->postJson($this->api('locations'), [
            'warehouse_id' => $w1->id, 'parent_id' => $rackInW2->id, 'code' => 'S1', 'name' => 'Shelf', 'type' => 'shelf',
        ])->assertJsonValidationErrors(['parent_id' => 'same warehouse']);
    }

    public function test_nested_locations_and_tree(): void
    {
        $warehouse = $this->makeWarehouse($this->makeFactory(), ['code' => 'RMS']);

        $rackA = $this->actingAs($this->admin)->postJson($this->api('locations'), [
            'warehouse_id' => $warehouse->id, 'code' => 'rack-a', 'name' => 'Rack A', 'type' => 'rack',
        ])->assertCreated()->json('data.id');

        foreach (['A1', 'A2'] as $shelf) {
            $this->actingAs($this->admin)->postJson($this->api('locations'), [
                'warehouse_id' => $warehouse->id, 'parent_id' => $rackA, 'code' => "SHELF-{$shelf}", 'name' => "Shelf {$shelf}", 'type' => 'shelf',
            ])->assertCreated();
        }

        $this->actingAs($this->admin)->getJson($this->api("warehouses/{$warehouse->id}/locations/tree"))
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.tree.0.code', 'RACK-A')
            ->assertJsonCount(2, 'data.tree.0.children')
            ->assertJsonPath('data.tree.0.children.1.code', 'SHELF-A2');

        $child = WarehouseLocation::where('code', 'SHELF-A1')->sole();
        $this->actingAs($this->admin)->getJson($this->api("locations/{$child->id}"))
            ->assertOk()->assertJsonPath('path.0.code', 'RACK-A');
    }

    public function test_circular_location_hierarchy_is_rejected(): void
    {
        $warehouse = $this->makeWarehouse($this->makeFactory());
        $zone = $this->makeLocation($warehouse, null, ['code' => 'Z']);
        $rack = $this->makeLocation($warehouse, $zone, ['code' => 'R']);
        $shelf = $this->makeLocation($warehouse, $rack, ['code' => 'S']);

        // Zone under its own grandchild.
        $this->actingAs($this->admin)->putJson($this->api("locations/{$zone->id}"), [
            'parent_id' => $shelf->id, 'code' => 'Z', 'name' => 'Zone', 'type' => 'zone',
        ])->assertJsonValidationErrors(['parent_id' => 'circular']);

        // Self as parent.
        $this->actingAs($this->admin)->putJson($this->api("locations/{$rack->id}"), [
            'parent_id' => $rack->id, 'code' => 'R', 'name' => 'Rack', 'type' => 'rack',
        ])->assertJsonValidationErrors('parent_id');

        $this->assertNull($zone->fresh()->parent_id);

        // A valid move is logged as a hierarchy change.
        $this->actingAs($this->admin)->putJson($this->api("locations/{$shelf->id}"), [
            'parent_id' => $zone->id, 'code' => 'S', 'name' => 'Shelf', 'type' => 'shelf',
        ])->assertOk()->assertJsonPath('data.parent_id', $zone->id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'location.moved', 'entity_id' => $shelf->id]);
    }

    public function test_location_depth_is_limited(): void
    {
        config(['erp.structure.max_location_depth' => 2]);
        $warehouse = $this->makeWarehouse($this->makeFactory());
        $rack = $this->makeLocation($warehouse);
        $shelf = $this->makeLocation($warehouse, $rack);

        $this->actingAs($this->admin)->postJson($this->api('locations'), [
            'warehouse_id' => $warehouse->id, 'parent_id' => $shelf->id, 'code' => 'BIN', 'name' => 'Bin', 'type' => 'bin',
        ])->assertJsonValidationErrors(['parent_id' => 'at most 2']);

        // Moving a two-level subtree under another node would exceed the depth.
        $other = $this->makeLocation($warehouse);
        $this->actingAs($this->admin)->putJson($this->api("locations/{$rack->id}"), [
            'parent_id' => $other->id, 'code' => $rack->code, 'name' => $rack->name, 'type' => 'rack',
        ])->assertJsonValidationErrors('parent_id');
    }

    public function test_location_code_is_unique_within_warehouse(): void
    {
        $factory = $this->makeFactory();
        $w1 = $this->makeWarehouse($factory);
        $this->makeLocation($w1, null, ['code' => 'A']);

        $this->actingAs($this->admin)->postJson($this->api('locations'), ['warehouse_id' => $w1->id, 'code' => 'a', 'name' => 'A', 'type' => 'rack'])
            ->assertJsonValidationErrors('code');
        $this->actingAs($this->admin)->postJson($this->api('locations'), ['warehouse_id' => $this->makeWarehouse($factory)->id, 'code' => 'A', 'name' => 'A', 'type' => 'rack'])
            ->assertCreated();
    }

    public function test_referenced_locations_cannot_be_destructively_deleted(): void
    {
        $warehouse = $this->makeWarehouse($this->makeFactory());
        $rack = $this->makeLocation($warehouse);
        $shelf = $this->makeLocation($warehouse, $rack);

        $this->actingAs($this->admin)->deleteJson($this->api("locations/{$rack->id}"))
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'child locations'));
        $this->assertModelExists($rack);

        // Future modules register referencing tables; such references also block deletion.
        Schema::create('test_stock_movements', fn ($t) => $t->foreignId('location_id'));
        DB::table('test_stock_movements')->insert(['location_id' => $shelf->id]);
        config(['erp.structure.location_references' => ['test_stock_movements' => 'location_id']]);

        $this->actingAs($this->admin)->deleteJson($this->api("locations/{$shelf->id}"))->assertStatus(409);
        $this->assertModelExists($shelf);

        // An unreferenced leaf can be removed (e.g. created by mistake).
        $mistake = $this->makeLocation($warehouse);
        $this->actingAs($this->admin)->deleteJson($this->api("locations/{$mistake->id}"))->assertOk();
        $this->assertModelMissing($mistake);
        $this->assertDatabaseHas('activity_logs', ['action' => 'location.deleted']);

        // Deletion needs its own permission.
        $editor = $this->createUserWithPermissions(['locations.view', 'locations.update', 'factories.access-all']);
        $this->actingAs($editor)->deleteJson($this->api("locations/{$shelf->id}"))->assertForbidden();
    }

    public function test_inactive_locations_cannot_be_selected_for_new_operations(): void
    {
        $factory = $this->makeFactory();
        $warehouse = $this->makeWarehouse($factory);
        $active = $this->makeLocation($warehouse, null, ['code' => 'ACT']);
        $inactive = $this->makeLocation($warehouse, null, ['code' => 'OFF', 'is_active' => false]);
        $inInactiveWarehouse = $this->makeLocation($this->makeWarehouse($factory, ['is_active' => false]), null, ['code' => 'GONE']);

        // Selection list for operations.
        $codes = collect($this->actingAs($this->admin)->getJson($this->api('locations?selectable=1&per_page=50'))->json('data'))->pluck('code');
        $this->assertContains('ACT', $codes);
        $this->assertNotContains('OFF', $codes);
        $this->assertNotContains('GONE', $codes);
        $this->assertSame([$active->id], WarehouseLocation::selectable()->pluck('id')->all());

        // An inactive location cannot be chosen as a new parent.
        $this->actingAs($this->admin)->postJson($this->api('locations'), [
            'warehouse_id' => $warehouse->id, 'parent_id' => $inactive->id, 'code' => 'NEW', 'name' => 'New', 'type' => 'bin',
        ])->assertJsonValidationErrors(['parent_id' => 'Inactive']);

        $this->assertNotNull($inInactiveWarehouse->id);
    }

    public function test_location_status_rules(): void
    {
        $warehouse = $this->makeWarehouse($this->makeFactory());
        $rack = $this->makeLocation($warehouse);
        $shelf = $this->makeLocation($warehouse, $rack);

        $this->actingAs($this->admin)->patchJson($this->api("locations/{$rack->id}/status"), ['is_active' => false])
            ->assertJsonValidationErrors(['is_active' => 'child locations']);

        $this->actingAs($this->admin)->patchJson($this->api("locations/{$shelf->id}/status"), ['is_active' => false])->assertOk();
        $this->actingAs($this->admin)->patchJson($this->api("locations/{$rack->id}/status"), ['is_active' => false])->assertOk();
        $this->actingAs($this->admin)->patchJson($this->api("locations/{$shelf->id}/status"), ['is_active' => true])
            ->assertJsonValidationErrors(['is_active' => 'parent']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'location.deactivated', 'entity_id' => $shelf->id]);
    }

    public function test_virtual_location_flags_are_saved_correctly(): void
    {
        $warehouse = $this->makeWarehouse($this->makeFactory());

        $this->actingAs($this->admin)->postJson($this->api('locations'), [
            'warehouse_id' => $warehouse->id, 'code' => 'TRANSIT', 'name' => 'In Transit', 'type' => 'other',
            'is_virtual' => true, 'is_pickable' => false, 'is_receivable' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_virtual', true)
            ->assertJsonPath('data.is_pickable', false)
            ->assertJsonPath('data.is_receivable', true);

        $location = WarehouseLocation::where('code', 'TRANSIT')->sole();
        $this->assertTrue($location->is_virtual);
        $this->assertFalse($location->is_pickable);

        // Defaults for a physical location.
        $this->actingAs($this->admin)->postJson($this->api('locations'), [
            'warehouse_id' => $warehouse->id, 'code' => 'BIN-1', 'name' => 'Bin 1', 'type' => 'bin',
        ])->assertJsonPath('data.is_virtual', false)->assertJsonPath('data.is_pickable', true);

        $this->actingAs($this->admin)->getJson($this->api('locations?is_virtual=1'))->assertJsonCount(1, 'data');

        // No stock columns exist on locations.
        $this->assertFalse(Schema::hasColumn('warehouse_locations', 'quantity'));
    }

    public function test_location_permissions_are_enforced(): void
    {
        $factory = $this->makeFactory();
        $warehouse = $this->makeWarehouse($factory);
        $viewer = $this->scopedUser($factory, ['locations.view', 'warehouses.view']);

        $this->actingAs($viewer)->getJson($this->api("warehouses/{$warehouse->id}/locations/tree"))->assertOk();
        $this->actingAs($viewer)->postJson($this->api('locations'), ['warehouse_id' => $warehouse->id, 'code' => 'X', 'name' => 'X', 'type' => 'bin'])
            ->assertForbidden();
        $this->actingAs($viewer)->putJson($this->api("warehouses/{$warehouse->id}"), ['code' => 'X', 'name' => 'X'])->assertForbidden();

        // Out-of-scope warehouse tree is hidden.
        $foreign = $this->makeWarehouse($this->makeFactory());
        $this->actingAs($viewer)->getJson($this->api("warehouses/{$foreign->id}/locations/tree"))->assertNotFound();
        $this->assertSame(1, Warehouse::where('factory_id', $factory->id)->count());
    }
}
