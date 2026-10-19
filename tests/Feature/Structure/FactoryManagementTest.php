<?php

namespace Tests\Feature\Structure;

use App\Models\Company;
use App\Models\Factory;
use App\Models\Role;

class FactoryManagementTest extends StructureTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'pune-1',
            'name' => 'Pune Plant',
            'type' => 'manufacturing',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'postal_code' => '411019',
            'country' => 'India',
            'contact_person' => 'R. Kulkarni',
            'contact_number' => '+91 20 4000 1234',
            'email' => 'plant@factory.test',
        ], $overrides);
    }

    public function test_admin_can_create_a_factory(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('factories'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'PUNE-1')
            ->assertJsonPath('data.company_id', Company::current()->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.created_by.id', $this->admin->id);

        $factory = Factory::sole();
        $this->assertSame($this->admin->id, $factory->created_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'factory.created', 'entity_type' => 'factory', 'entity_id' => $factory->id]);
    }

    public function test_factory_validation(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('factories'), ['code' => '', 'name' => '', 'type' => 'spaceport', 'postal_code' => '12'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'type', 'postal_code']);
    }

    public function test_duplicate_factory_code_is_rejected_within_the_same_company(): void
    {
        $this->makeFactory(['code' => 'PUNE-1']);

        $this->actingAs($this->admin)->postJson($this->api('factories'), $this->payload(['code' => 'pune-1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'already used']);
    }

    public function test_same_factory_code_is_allowed_in_another_company(): void
    {
        // Uniqueness rule: factory code is unique per company, not globally.
        $other = Company::factory()->create(['code' => 'SUB']);
        $this->makeFactory(['code' => 'PUNE-1', 'company_id' => $other->id]);

        $this->actingAs($this->admin)->postJson($this->api('factories'), $this->payload())->assertCreated();

        $this->assertSame(2, Factory::where('code', 'PUNE-1')->count());
    }

    public function test_admin_can_update_and_deactivate_a_factory(): void
    {
        $factory = $this->makeFactory(['code' => 'F1']);

        $this->actingAs($this->admin)->putJson($this->api("factories/{$factory->id}"), $this->payload(['code' => 'F1', 'name' => 'Renamed']))
            ->assertOk()->assertJsonPath('data.name', 'Renamed');

        // Status cannot be changed through the general update.
        $this->actingAs($this->admin)->putJson($this->api("factories/{$factory->id}"), $this->payload(['code' => 'F1', 'is_active' => false]))
            ->assertJsonValidationErrors('is_active');

        $this->actingAs($this->admin)->patchJson($this->api("factories/{$factory->id}/status"), ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('activity_logs', ['action' => 'factory.updated', 'entity_id' => $factory->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'factory.deactivated', 'entity_id' => $factory->id]);
    }

    public function test_factories_cannot_be_deleted(): void
    {
        $factory = $this->makeFactory();
        $this->actingAs($this->admin)->deleteJson($this->api("factories/{$factory->id}"))->assertStatus(405);
        $this->assertModelExists($factory);
    }

    public function test_role_permissions_are_enforced_by_factory_apis(): void
    {
        $factory = $this->makeFactory();
        $viewer = $this->scopedUser($factory, ['factories.view']);

        $this->actingAs($viewer)->getJson($this->api('factories'))->assertOk();
        $this->actingAs($viewer)->postJson($this->api('factories'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->putJson($this->api("factories/{$factory->id}"), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->patchJson($this->api("factories/{$factory->id}/status"), ['is_active' => false])->assertForbidden();

        // Reactivation needs factories.update, deactivation needs factories.deactivate.
        $deactivator = $this->scopedUser($factory, ['factories.view', 'factories.deactivate']);
        $this->actingAs($deactivator)->patchJson($this->api("factories/{$factory->id}/status"), ['is_active' => false])->assertOk();
        $this->actingAs($deactivator)->patchJson($this->api("factories/{$factory->id}/status"), ['is_active' => true])->assertForbidden();

        // Core roles were not granted structure permissions automatically.
        foreach (['Factory Manager', 'Store Keeper', 'QC', 'Accountant'] as $roleName) {
            $this->assertFalse(Role::findByName($roleName, 'web')->hasPermissionTo('factories.create'), $roleName);
            $this->assertFalse(Role::findByName($roleName, 'web')->hasPermissionTo('warehouses.update'), $roleName);
        }
        $this->assertTrue(Role::primaryAdmin()->hasPermissionTo('factories.access-all'));
    }

    public function test_users_cannot_access_unauthorized_factory_records(): void
    {
        $mine = $this->makeFactory(['name' => 'Mine']);
        $other = $this->makeFactory(['name' => 'Other']);
        $user = $this->scopedUser($mine, ['factories.view', 'factories.update', 'departments.view', 'departments.create', 'warehouses.view']);
        $otherDept = $this->makeDepartment($other);
        $otherWarehouse = $this->makeWarehouse($other);

        $this->actingAs($user)->getJson($this->api('factories'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');

        $this->actingAs($user)->getJson($this->api("factories/{$other->id}"))->assertNotFound();
        $this->actingAs($user)->putJson($this->api("factories/{$other->id}"), $this->payload())->assertNotFound();
        $this->actingAs($user)->getJson($this->api("departments/{$otherDept->id}"))->assertNotFound();
        $this->actingAs($user)->getJson($this->api("warehouses/{$otherWarehouse->id}"))->assertNotFound();
        $this->actingAs($user)->getJson($this->api('departments'))->assertJsonCount(0, 'data');

        // Cannot create records in a factory outside the scope.
        $this->actingAs($user)->postJson($this->api('departments'), ['factory_id' => $other->id, 'code' => 'X', 'name' => 'X'])
            ->assertJsonValidationErrors('factory_id');

        // A user with the access-all scope sees both.
        $global = $this->createUserWithPermissions(['factories.view', 'factories.access-all']);
        $this->actingAs($global)->getJson($this->api('factories'))->assertJsonCount(2, 'data');
    }
}
