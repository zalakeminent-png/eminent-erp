<?php

namespace Tests\Feature\Users;

use App\Models\Role;
use App\Models\User;
use App\Services\UserSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = $this->createAdmin(['email' => 'admin@factory.test']);
    }

    private function roleId(string $name): int
    {
        return Role::findByName($name, 'web')->id;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Meera',
            'last_name' => 'Iyer',
            'email' => 'meera@factory.test',
            'employee_code' => 'emp-001',
            'mobile' => '+91 98200 12345',
            'role_ids' => [$this->roleId('Store Keeper')],
        ], $overrides);
    }

    public function test_unauthorized_user_cannot_view_user_management(): void
    {
        $storeKeeper = $this->createUserWithRole('Store Keeper');

        $this->actingAs($storeKeeper)->getJson($this->api('users'))->assertForbidden();
        $this->actingAs($storeKeeper)->getJson($this->api("users/{$this->admin->id}"))->assertForbidden();
        $this->actingAs($storeKeeper)->postJson($this->api('users'), $this->payload())->assertForbidden();
    }

    public function test_admin_can_list_search_filter_and_sort_users(): void
    {
        $this->createUserWithRole('QC', ['first_name' => 'Zara', 'last_name' => 'Khan', 'employee_code' => 'QC-9']);
        $this->createUserWithRole('Accountant', ['first_name' => 'Arun', 'last_name' => 'Das', 'is_active' => false]);

        $this->actingAs($this->admin)->getJson($this->api('users?search=zara'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Zara');

        $this->actingAs($this->admin)->getJson($this->api('users?search=qc-9'))->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->getJson($this->api('users?status=inactive'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Arun');

        $this->actingAs($this->admin)->getJson($this->api('users?role_id='.$this->roleId('QC')))
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->getJson($this->api('users?sort=name&direction=desc&per_page=5'))
            ->assertOk()->assertJsonPath('data.0.first_name', 'Zara')->assertJsonPath('meta.per_page', 5);

        $this->actingAs($this->admin)->getJson($this->api('users?sort=password'))->assertUnprocessable();
    }

    public function test_admin_can_create_a_user(): void
    {
        $response = $this->actingAs($this->admin)->postJson($this->api('users'), $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'meera@factory.test')
            ->assertJsonPath('data.employee_code', 'EMP-001')
            ->assertJsonPath('data.roles.0.name', 'Store Keeper')
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'meera@factory.test')->sole();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole('Store Keeper'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.created', 'entity_id' => $user->id, 'actor_id' => $this->admin->id]);
    }

    public function test_duplicate_user_email_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('users'), $this->payload(['email' => 'ADMIN@factory.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'already exists']);
    }

    public function test_user_creation_validates_input(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('users'), [
            'first_name' => '', 'email' => 'not-an-email', 'role_ids' => [], 'mobile' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'role_ids', 'mobile']);

        $inactiveRole = Role::create(['name' => 'Dormant', 'guard_name' => 'web', 'is_active' => false]);
        $this->actingAs($this->admin)->postJson($this->api('users'), $this->payload(['role_ids' => [$inactiveRole->id]]))
            ->assertJsonValidationErrors('role_ids.0');
    }

    public function test_admin_can_update_a_user(): void
    {
        $user = $this->createUserWithRole('Store Keeper');

        $this->actingAs($this->admin)->putJson($this->api("users/{$user->id}"), $this->payload([
            'first_name' => 'Updated',
            'email' => $user->email,
            'role_ids' => [$this->roleId('QC'), $this->roleId('Accountant')],
        ]))->assertOk()
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonCount(2, 'data.roles');

        $this->assertEqualsCanonicalizing(['QC', 'Accountant'], $user->fresh()->getRoleNames()->all());
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.updated', 'entity_id' => $user->id]);
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $user = $this->createUserWithRole('Store Keeper');

        $this->actingAs($this->admin)->patchJson($this->api("users/{$user->id}/status"), ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertNotNull($user->fresh()->deactivated_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.deactivated', 'entity_id' => $user->id]);

        $this->actingAs($this->admin)->patchJson($this->api("users/{$user->id}/status"), ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.activated', 'entity_id' => $user->id]);
    }

    public function test_deactivated_user_loses_authenticated_access(): void
    {
        $user = $this->createUserWithRole('Factory Manager');
        $this->actingAs($user)->getJson($this->api('dashboard'))->assertOk();

        $this->actingAs($this->admin)->patchJson($this->api("users/{$user->id}/status"), ['is_active' => false])->assertOk();

        $this->actingAs($user->fresh())->getJson($this->api('dashboard'))->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_deactivation_revokes_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->createUserWithRole('QC');
        DB::table('sessions')->insert([
            ['id' => 'sess-a', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sess-b', 'user_id' => $this->admin->id, 'payload' => 'x', 'last_activity' => time()],
        ]);

        $this->assertSame(1, app(UserSessions::class)->terminate($user));
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-a']);
        $this->assertDatabaseHas('sessions', ['id' => 'sess-b']);
    }

    public function test_status_change_requires_matching_permission(): void
    {
        $user = $this->createUserWithRole('QC');
        $manager = $this->createUserWithPermissions(['users.view', 'users.activate', 'quality-control.view']);

        // Has users.activate but not users.deactivate.
        $this->actingAs($manager)->patchJson($this->api("users/{$user->id}/status"), ['is_active' => false])->assertForbidden();
    }

    public function test_final_administrator_cannot_be_deactivated(): void
    {
        $otherAdmin = $this->createAdmin();
        $otherAdmin->forceFill(['is_active' => false])->save();

        // Cannot deactivate self.
        $this->actingAs($this->admin)->patchJson($this->api("users/{$this->admin->id}/status"), ['is_active' => false])
            ->assertForbidden();

        // A second admin tries to deactivate the only *active* admin.
        $otherAdmin->forceFill(['is_active' => true])->save();
        $this->actingAs($otherAdmin)->patchJson($this->api("users/{$this->admin->id}/status"), ['is_active' => false])
            ->assertOk();
        $this->actingAs($this->admin->fresh())->patchJson($this->api("users/{$otherAdmin->id}/status"), ['is_active' => false])
            ->assertForbidden(); // the first admin is now inactive and is blocked outright

        // With exactly one active admin left, nobody can deactivate them.
        $delegate = $this->createUserWithPermissions(\App\Models\Permission::pluck('name')->all());
        $this->actingAs($delegate)->patchJson($this->api("users/{$otherAdmin->id}/status"), ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->assertTrue($otherAdmin->fresh()->is_active);
    }

    public function test_final_administrator_role_cannot_be_removed(): void
    {
        $delegate = $this->createUserWithPermissions(\App\Models\Permission::pluck('name')->all());

        $this->actingAs($delegate)->putJson($this->api("users/{$this->admin->id}"), [
            'first_name' => $this->admin->first_name,
            'last_name' => $this->admin->last_name,
            'email' => $this->admin->email,
            'role_ids' => [$this->roleId('Factory Manager')],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        $this->assertTrue($this->admin->fresh()->hasRole('Admin'));
    }

    public function test_user_cannot_change_own_roles(): void
    {
        $this->actingAs($this->admin)->putJson($this->api("users/{$this->admin->id}"), [
            'first_name' => 'Self', 'last_name' => 'Edit', 'email' => $this->admin->email,
            'role_ids' => [$this->roleId('Admin'), $this->roleId('QC')],
        ])->assertForbidden();
    }

    public function test_user_manager_cannot_escalate_privileges(): void
    {
        $manager = $this->createUserWithPermissions(['users.view', 'users.create', 'users.update']);
        $managerRole = $manager->roles->first();

        // Cannot create a user with a more powerful role.
        $this->actingAs($manager)->postJson($this->api('users'), $this->payload(['role_ids' => [$this->roleId('Admin')]]))
            ->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        // Cannot give themselves Admin.
        $this->actingAs($manager)->putJson($this->api("users/{$manager->id}"), [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => $manager->email,
            'role_ids' => [$managerRole->id, $this->roleId('Admin')],
        ])->assertForbidden();

        // Cannot edit (e.g. take over the email of) a more privileged user.
        $this->actingAs($manager)->putJson($this->api("users/{$this->admin->id}"), [
            'first_name' => 'X', 'last_name' => 'Y', 'email' => 'attacker@factory.test',
            'role_ids' => [$this->roleId('Admin')],
        ])->assertForbidden();

        $this->assertSame('admin@factory.test', $this->admin->fresh()->email);
        $this->assertFalse($manager->fresh()->hasRole('Admin'));
    }

    public function test_users_cannot_be_deleted_via_api(): void
    {
        $user = $this->createUserWithRole('QC');

        $this->actingAs($this->admin)->deleteJson($this->api("users/{$user->id}"))->assertStatus(405);
        $this->assertModelExists($user);
    }

    public function test_user_details_hide_sensitive_fields(): void
    {
        $user = $this->createUserWithRole('QC');

        $this->actingAs($this->admin)->getJson($this->api("users/{$user->id}"))
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->actingAs($this->admin)->getJson($this->api('users/999999'))
            ->assertNotFound()->assertJsonPath('message', 'The requested record was not found.');
    }
}
