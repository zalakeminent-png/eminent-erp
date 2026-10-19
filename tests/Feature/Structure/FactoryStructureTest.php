<?php

namespace Tests\Feature\Structure;

use App\Models\ActivityLog;

class FactoryStructureTest extends StructureTestCase
{
    public function test_structure_tree_returns_the_correct_hierarchy(): void
    {
        $factory = $this->makeFactory(['code' => 'PUNE', 'name' => 'Pune Plant']);
        $this->makeDepartment($factory, ['code' => 'CAST', 'name' => 'Casting']);
        $this->makeDepartment($factory, ['code' => 'QC', 'name' => 'Quality Control']);
        $store = $this->makeWarehouse($factory, ['code' => 'RMS', 'name' => 'Raw Material Store']);
        $rackA = $this->makeLocation($store, null, ['code' => 'RACK-A', 'name' => 'Rack A']);
        $this->makeLocation($store, $rackA, ['code' => 'SHELF-A1', 'name' => 'Shelf A1']);
        $this->makeLocation($store, $rackA, ['code' => 'SHELF-A2', 'name' => 'Shelf A2']);

        $this->actingAs($this->admin)->getJson($this->api('factory-structure'))
            ->assertOk()
            ->assertJsonPath('data.company.name', config('erp.company.default_name'))
            ->assertJsonPath('data.totals', ['factories' => 1, 'departments' => 2, 'warehouses' => 1, 'locations' => 3])
            ->assertJsonPath('data.factories.0.code', 'PUNE')
            ->assertJsonPath('data.factories.0.departments.0.name', 'Casting')
            ->assertJsonPath('data.factories.0.warehouses.0.code', 'RMS')
            ->assertJsonPath('data.factories.0.warehouses.0.locations.0.code', 'RACK-A')
            ->assertJsonPath('data.factories.0.warehouses.0.locations.0.children.1.code', 'SHELF-A2')
            ->assertJsonMissingPath('data.factories.0.warehouses.0.locations.0.quantity');

        $this->actingAs($this->admin)->getJson($this->api("factory-structure/{$factory->id}"))
            ->assertOk()->assertJsonCount(1, 'data.factories');
    }

    public function test_structure_search_and_status_filters(): void
    {
        $pune = $this->makeFactory(['code' => 'PUNE', 'name' => 'Pune Plant']);
        $nashik = $this->makeFactory(['code' => 'NSK', 'name' => 'Nashik Plant']);
        $this->makeDepartment($pune, ['code' => 'CAST', 'name' => 'Casting']);
        $store = $this->makeWarehouse($nashik, ['code' => 'FGS', 'name' => 'Finished Goods']);
        $rack = $this->makeLocation($store, null, ['code' => 'RACK-Z', 'name' => 'Rack Z']);
        $this->makeLocation($store, $rack, ['code' => 'BIN-42', 'name' => 'Bin 42']);
        $this->makeLocation($store, null, ['code' => 'OLD', 'name' => 'Old Area', 'is_active' => false]);

        // Location search keeps the path to the match only.
        $this->actingAs($this->admin)->getJson($this->api('factory-structure?search=bin-42'))
            ->assertJsonCount(1, 'data.factories')
            ->assertJsonPath('data.factories.0.code', 'NSK')
            ->assertJsonCount(0, 'data.factories.0.departments')
            ->assertJsonCount(1, 'data.factories.0.warehouses.0.locations')
            ->assertJsonPath('data.factories.0.warehouses.0.locations.0.children.0.code', 'BIN-42');

        $this->actingAs($this->admin)->getJson($this->api('factory-structure?search=casting'))
            ->assertJsonPath('data.factories.0.code', 'PUNE')->assertJsonPath('data.totals.factories', 1);

        $this->actingAs($this->admin)->getJson($this->api('factory-structure?status=active'))
            ->assertJsonPath('data.totals.locations', 2);

        $this->actingAs($this->admin)->getJson($this->api('factory-structure?status=inactive'))
            ->assertJsonPath('data.totals.locations', 1)
            ->assertJsonPath('data.factories.0.warehouses.0.locations.0.code', 'OLD');
    }

    public function test_structure_is_scoped_and_permissioned(): void
    {
        $mine = $this->makeFactory(['name' => 'Mine']);
        $this->makeFactory(['name' => 'Other']);

        $viewer = $this->scopedUser($mine, ['factory-structure.view']);
        $this->actingAs($viewer)->getJson($this->api('factory-structure'))
            ->assertOk()->assertJsonPath('data.totals.factories', 1)->assertJsonPath('data.factories.0.name', 'Mine');
        $this->actingAs($viewer)->getJson($this->api('factory-structure/export'))->assertForbidden();

        $noAccess = $this->createUserWithPermissions(['dashboard.view']);
        $this->actingAs($noAccess)->getJson($this->api('factory-structure'))->assertForbidden();
    }

    public function test_structure_export_csv(): void
    {
        $factory = $this->makeFactory(['code' => 'PUNE', 'name' => '=HYPERLINK("x")']);
        $warehouse = $this->makeWarehouse($factory, ['code' => 'RMS']);
        $this->makeLocation($warehouse, null, ['code' => 'RACK-A']);

        $response = $this->actingAs($this->admin)->get($this->api('factory-structure/export'));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('"Factory Code",Factory,Level,Code,Name,"Parent Path",Type,Status', $csv);
        $this->assertStringContainsString('Location,RACK-A', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv); // formula neutralised
        $this->assertTrue(ActivityLog::where('action', 'factory-structure.exported')->exists());
    }

    public function test_factory_list_search_filter_and_pagination(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeFactory(['code' => sprintf('F%02d', $i), 'name' => "Plant {$i}", 'city' => $i === 3 ? 'Chennai' : 'Pune']);
        }
        $this->makeFactory(['code' => 'WH', 'name' => 'Depot', 'type' => 'warehouse', 'is_active' => false]);

        $this->actingAs($this->admin)->getJson($this->api('factories?per_page=5&page=3'))
            ->assertOk()->assertJsonPath('meta.total', 13)->assertJsonPath('meta.current_page', 3)->assertJsonCount(3, 'data');
        $this->actingAs($this->admin)->getJson($this->api('factories?search=chennai'))->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson($this->api('factories?type=warehouse'))->assertJsonPath('data.0.code', 'WH');
        $this->actingAs($this->admin)->getJson($this->api('factories?status=inactive'))->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson($this->api('factories?sort=code&direction=desc'))->assertJsonPath('data.0.code', 'WH');
        $this->actingAs($this->admin)->getJson($this->api('factories?sort=password'))->assertUnprocessable();
    }

    public function test_activity_logs_record_important_structure_changes(): void
    {
        $this->actingAs($this->admin);
        $factoryId = $this->postJson($this->api('factories'), ['code' => 'P1', 'name' => 'Plant', 'type' => 'manufacturing'])->json('data.id');
        $deptId = $this->postJson($this->api('departments'), ['factory_id' => $factoryId, 'code' => 'D1', 'name' => 'Dept'])->json('data.id');
        $this->putJson($this->api("departments/{$deptId}"), ['code' => 'D1', 'name' => 'Dept Renamed'])->assertOk();
        $whId = $this->postJson($this->api('warehouses'), ['factory_id' => $factoryId, 'code' => 'W1', 'name' => 'Store'])->json('data.id');
        $locId = $this->postJson($this->api('locations'), ['warehouse_id' => $whId, 'code' => 'L1', 'name' => 'Loc', 'type' => 'zone'])->json('data.id');
        $this->patchJson($this->api("locations/{$locId}/status"), ['is_active' => false])->assertOk();
        $this->patchJson($this->api("warehouses/{$whId}/status"), ['is_active' => false])->assertOk();
        $this->patchJson($this->api("departments/{$deptId}/status"), ['is_active' => false])->assertOk();

        $actions = ActivityLog::pluck('action')->all();
        foreach ([
            'factory.created', 'department.created', 'department.updated', 'warehouse.created',
            'location.created', 'location.deactivated', 'warehouse.deactivated', 'department.deactivated',
        ] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $log = ActivityLog::where('action', 'department.updated')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame('department', $log->entity_type);
        $this->assertSame(['from' => 'Dept', 'to' => 'Dept Renamed'], $log->metadata['changes']['name']);
        $this->assertSame('warehouse-location', ActivityLog::where('action', 'location.created')->value('entity_type'));
    }
}
