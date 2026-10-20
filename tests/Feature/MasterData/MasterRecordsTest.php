<?php

namespace Tests\Feature\MasterData;

use App\Models\MasterData\BusinessPartner;
use App\Models\MasterData\ItemCategory;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\MasterDataSeeder;

class MasterRecordsTest extends MasterDataTestCase
{
    public function test_business_partner_can_be_both_customer_and_supplier(): void
    {
        $response = $this->actingAs($this->admin)->postJson($this->api('business-partners'), [
            'name' => 'Acme Metals Pvt Ltd', 'is_customer' => true, 'is_supplier' => true,
            'gstin' => '27AAPFU0939F1ZV', 'pan' => 'AAPFU0939F',
            'contacts' => [['name' => 'R. Sharma', 'email' => 'r@acme.test', 'is_primary' => true], ['name' => 'Accounts']],
            'addresses' => [
                ['address_type' => 'billing', 'address_line_1' => 'Plot 12, MIDC', 'city' => 'Pune', 'is_default' => true],
                ['address_type' => 'shipping', 'address_line_1' => 'Gate 2, Chakan', 'city' => 'Pune', 'is_default' => true],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.party_type', 'both')
            ->assertJsonPath('data.code', 'BP-00001')
            ->assertJsonCount(2, 'data.contacts')
            ->assertJsonCount(2, 'data.addresses');
        $id = $response->json('data.id');

        $this->actingAs($this->admin)->getJson($this->api('business-partners?party_type=supplier'))->assertJsonPath('meta.total', 1);
        $this->actingAs($this->admin)->getJson($this->api('business-partners?party_type=customer'))->assertJsonPath('meta.total', 1);

        // Neither role is invalid; GSTIN must match PAN.
        $this->actingAs($this->admin)->postJson($this->api('business-partners'), ['name' => 'X', 'is_customer' => false, 'is_supplier' => false])
            ->assertJsonValidationErrors('is_customer');
        $this->actingAs($this->admin)->postJson($this->api('business-partners'), ['name' => 'Y', 'is_customer' => true, 'is_supplier' => false, 'gstin' => '27AAPFU0939F1ZV', 'pan' => 'BBBBB1234B'])
            ->assertJsonValidationErrors('gstin');

        // Accountant (seeded): view only.
        $accountant = $this->createUserWithRole('Accountant');
        $this->actingAs($accountant)->getJson($this->api("business-partners/{$id}"))->assertOk();
        $this->actingAs($accountant)->putJson($this->api("business-partners/{$id}"), ['name' => 'Z', 'is_customer' => true, 'is_supplier' => false])->assertForbidden();
        $this->assertSame('both', BusinessPartner::find($id)->partyType());
    }

    public function test_category_hierarchy_rules(): void
    {
        $rm = $this->category('RM');
        $child = $this->actingAs($this->admin)->postJson($this->api('item-categories'), ['code' => 'rm-ss', 'name' => 'SS Raw Material', 'parent_id' => $rm->id])
            ->assertCreated()->assertJsonPath('data.parent.code', 'RM')->json('data.id');

        // Circular parent rejected.
        $this->actingAs($this->admin)->putJson($this->api("item-categories/{$rm->id}"), ['code' => 'RM', 'name' => 'Raw Material', 'parent_id' => $child])
            ->assertJsonValidationErrors(['parent_id' => 'itself']);

        // Used / default categories cannot be deleted; unused custom ones can.
        $this->makeItem('RM-X', 'RM-SS');
        $this->actingAs($this->admin)->deleteJson($this->api("item-categories/{$child}"))->assertStatus(409);
        $this->actingAs($this->admin)->deleteJson($this->api("item-categories/{$rm->id}"))->assertStatus(409);
        $spare = $this->actingAs($this->admin)->postJson($this->api('item-categories'), ['code' => 'SPARE', 'name' => 'Spare'])->json('data.id');
        $this->actingAs($this->admin)->deleteJson($this->api("item-categories/{$spare}"))->assertOk();

        // Tree endpoint.
        $tree = collect($this->actingAs($this->admin)->getJson($this->api('item-categories/tree'))->assertOk()->json('data'));
        $this->assertSame('RM-SS', collect($tree->firstWhere('code', 'RM')['children'])->first()['code']);
        $this->assertSame(7, $tree->count());
    }

    public function test_machine_capacity_processes_and_scope(): void
    {
        [$factory, $department] = $this->factoryAndDepartment();

        $this->actingAs($this->admin)->postJson($this->api('machines'), [
            'department_id' => $department->id, 'code' => 'cnc-01', 'name' => 'CNC Machine 01', 'capacity_qty' => '500',
        ])->assertJsonValidationErrors(['capacity_uom_id', 'capacity_period', 'capacity_period_hours']);

        $id = $this->actingAs($this->admin)->postJson($this->api('machines'), [
            'department_id' => $department->id, 'code' => 'cnc-01', 'name' => 'CNC Machine 01', 'machine_type' => 'CNC Lathe',
            'capacity_qty' => '500', 'capacity_uom_id' => $this->uom('PCS')->id, 'capacity_period' => 'shift', 'capacity_period_hours' => '8',
            'working_hours_per_day' => '16', 'process_ids' => [$this->process('CNC')->id, $this->process('DRILL')->id],
        ])->assertCreated()
            ->assertJsonPath('data.factory_id', $factory->id)
            ->assertJsonPath('data.capacity_qty', '500')
            ->assertJsonCount(2, 'data.processes')
            ->json('data.id');

        // A user scoped to another factory cannot see it.
        [$otherFactory] = $this->factoryAndDepartment();
        $scoped = $this->createUserWithPermissions(['machines.view']);
        $scoped->forceFill(['factory_id' => $otherFactory->id])->save();
        $this->actingAs($scoped->fresh())->getJson($this->api('machines'))->assertJsonCount(0, 'data');
        $this->actingAs($scoped->fresh())->getJson($this->api("machines/{$id}"))->assertNotFound();
    }

    public function test_unauthorized_api_requests_are_rejected(): void
    {
        $this->getJson($this->api('items'))->assertUnauthorized();
        $this->getJson($this->api('boms'))->assertUnauthorized();
        $this->postJson($this->api('imports'), [])->assertUnauthorized();

        $nobody = $this->createUserWithPermissions(['dashboard.view']);
        foreach (['uom-conversions', 'boms', 'product-routings', 'qc-specifications', 'business-partners', 'machines', 'manufacturing-processes', 'master-data/dashboard', 'imports'] as $path) {
            $this->actingAs($nobody)->getJson($this->api($path))->assertForbidden();
        }
        foreach (['item-categories', 'uoms', 'material-grades', 'manufacturing-processes', 'machines', 'business-partners'] as $path) {
            $this->actingAs($nobody)->postJson($this->api($path), [])->assertForbidden();
        }
    }

    public function test_default_role_permissions_are_applied_once(): void
    {
        $manager = Role::findByName('Factory Manager', 'web');
        $this->assertTrue($manager->hasPermissionTo('boms.create'));
        $this->assertFalse($manager->hasPermissionTo('boms.approve'));
        $this->assertFalse(Role::findByName('Store Keeper', 'web')->hasPermissionTo('items.create'));
        $this->assertTrue(Role::findByName('QC', 'web')->hasPermissionTo('qc-specifications.update'));
        $this->assertFalse(Role::findByName('QC', 'web')->hasPermissionTo('qc-specifications.approve'));
        $this->assertSame(Permission::count(), Role::primaryAdmin()->permissions()->count());

        // An administrator customisation survives re-seeding.
        $manager->revokePermissionTo('boms.create');
        $this->seed(MasterDataSeeder::class);
        $this->assertFalse($manager->fresh()->hasPermissionTo('boms.create'));
        $this->assertSame(7, ItemCategory::count());
    }

    public function test_master_data_dashboard_counts(): void
    {
        $this->makeItem('RM-1');
        $this->actingAs($this->admin)->getJson($this->api('master-data/dashboard'))
            ->assertOk()
            ->assertJsonPath('data.sections.items.active', 1)
            ->assertJsonPath('data.sections.uoms.active', 11)
            ->assertJsonPath('data.sections.processes.active', 14);

        $this->actingAs($this->createUserWithRole('Store Keeper'))->getJson($this->api('master-data/dashboard'))
            ->assertOk()->assertJsonMissingPath('data.sections.boms')->assertJsonPath('data.recent_activity', null);
    }
}
