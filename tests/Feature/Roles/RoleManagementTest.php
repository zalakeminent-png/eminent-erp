<?php

namespace Tests\Feature\Roles;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
    }

    public function test_default_roles_are_seeded_as_core_roles(): void
    {
        $this->actingAs($this->admin)->getJson($this->api('roles?all=1'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonFragment(['name' => 'Factory Manager', 'is_system' => true]);
    }

    public function test_admin_can_create_a_custom_role(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('roles'), [
            'name' => 'Shift Supervisor',
            'description' => 'Oversees a production shift.',
            'permissions' => ['dashboard.view', 'production-tracking.view'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Shift Supervisor')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions_count', 2);

        $role = Role::findByName('Shift Supervisor', 'web');
        $this->assertTrue($role->hasPermissionTo('production-tracking.view'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'role.created', 'entity_id' => $role->id]);

        // Name must be unique.
        $this->actingAs($this->admin)->postJson($this->api('roles'), ['name' => 'Shift Supervisor'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_admin_can_assign_and_remove_permissions(): void
    {
        $role = Role::create(['name' => 'Planner', 'guard_name' => 'web']);
        $role->givePermissionTo('dashboard.view');

        $this->actingAs($this->admin)->putJson($this->api("roles/{$role->id}/permissions"), [
            'permissions' => ['production-planning.view', 'production-planning.approve', 'reports.export'],
        ])->assertOk()->assertJsonPath('data.permissions', ['production-planning.approve', 'production-planning.view', 'reports.export']);

        $role->refresh();
        $this->assertTrue($role->hasPermissionTo('production-planning.approve'));
        $this->assertFalse($role->hasPermissionTo('dashboard.view'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'role.permissions_updated', 'entity_id' => $role->id]);

        $this->actingAs($this->admin)->getJson($this->api("roles/{$role->id}/permissions"))
            ->assertOk()->assertJsonCount(3, 'data.permissions');
    }

    public function test_permission_payload_is_validated(): void
    {
        $role = Role::create(['name' => 'Planner', 'guard_name' => 'web']);

        $this->actingAs($this->admin)->putJson($this->api("roles/{$role->id}/permissions"), [
            'permissions' => ['users.view', 'superpowers.grant'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');

        $this->actingAs($this->admin)->putJson($this->api("roles/{$role->id}/permissions"), [])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
    }

    public function test_permissions_are_enforced_by_apis(): void
    {
        $viewer = $this->createUserWithPermissions(['roles.view']);

        $this->actingAs($viewer)->getJson($this->api('roles'))->assertOk();
        $this->actingAs($viewer)->postJson($this->api('roles'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer)->putJson($this->api('roles/1/permissions'), ['permissions' => []])->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->api('roles/1'))->assertForbidden();
        $this->actingAs($viewer)->getJson($this->api('company'))->assertForbidden();
        $this->actingAs($viewer)->getJson($this->api('activity-logs'))->assertForbidden();
        $this->actingAs($viewer)->getJson($this->api('dashboard'))->assertForbidden();
    }

    public function test_user_cannot_modify_their_own_permissions(): void
    {
        $roleManager = $this->createUserWithPermissions(['roles.view', 'roles.assign-permissions']);
        $ownRole = $roleManager->roles->first();

        // Granting their own role more permissions is refused.
        $this->actingAs($roleManager)->putJson($this->api("roles/{$ownRole->id}/permissions"), [
            'permissions' => ['roles.view', 'roles.assign-permissions', 'users.create'],
        ])->assertForbidden();

        // Granting permissions they do not hold to another role they could later receive is refused.
        $other = Role::create(['name' => 'Side Door', 'guard_name' => 'web']);
        $this->actingAs($roleManager)->putJson($this->api("roles/{$other->id}/permissions"), [
            'permissions' => ['users.create', 'company.update'],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions');

        // Editing the primary admin role is refused.
        $this->actingAs($this->admin)->putJson($this->api('roles/'.Role::primaryAdmin()->id.'/permissions'), [
            'permissions' => ['dashboard.view'],
        ])->assertUnprocessable();

        $this->assertFalse($roleManager->fresh()->can('users.create'));
        $this->assertSame(0, $other->permissions()->count());
    }

    public function test_core_and_assigned_roles_cannot_be_deleted(): void
    {
        $core = Role::findByName('QC', 'web');
        $this->actingAs($this->admin)->deleteJson($this->api("roles/{$core->id}"))->assertUnprocessable();

        $custom = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
        $holder = User::factory()->create();
        $holder->assignRole($custom);

        $this->actingAs($this->admin)->deleteJson($this->api("roles/{$custom->id}"))->assertStatus(409);

        $holder->removeRole($custom);
        $this->actingAs($this->admin)->deleteJson($this->api("roles/{$custom->id}"))->assertOk();
        $this->assertModelMissing($custom);
        $this->assertDatabaseHas('activity_logs', ['action' => 'role.deleted']);
    }

    public function test_core_role_protection_on_update(): void
    {
        $admin = Role::primaryAdmin();

        $this->actingAs($this->admin)->putJson($this->api("roles/{$admin->id}"), ['name' => 'Root'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->actingAs($this->admin)->putJson($this->api("roles/{$admin->id}"), ['name' => 'Admin', 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->actingAs($this->admin)->putJson($this->api("roles/{$admin->id}"), ['name' => 'Admin', 'description' => 'Superusers'])
            ->assertOk()->assertJsonPath('data.description', 'Superusers');
    }

    public function test_role_details_list_assigned_users(): void
    {
        $qc = $this->createUserWithRole('QC');

        $this->actingAs($this->admin)->getJson($this->api('roles/'.Role::findByName('QC')->id))
            ->assertOk()
            ->assertJsonPath('data.users_count', 1)
            ->assertJsonPath('users.0.id', $qc->id);
    }

    public function test_permission_catalogue_is_grouped_for_the_matrix(): void
    {
        $response = $this->actingAs($this->admin)->getJson($this->api('permissions'))->assertOk();

        $modules = collect($response->json('data.modules'))->keyBy('key');
        $this->assertSame(['view', 'create', 'update', 'delete', 'approve', 'export'], $response->json('data.standard_actions'));
        $this->assertTrue($modules['orders']['reserved']);
        $this->assertFalse($modules['users']['reserved']);
        $this->assertContains('users.reset-password', collect($modules['users']['permissions'])->pluck('name'));
        // Inapplicable actions are not created.
        $this->assertNotContains('reports.delete', collect($modules['reports']['permissions'])->pluck('name'));
    }
}
