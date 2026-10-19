<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_admin_can_log_in(): void
    {
        $admin = $this->createAdmin(['email' => 'admin@factory.test']);

        $response = $this->postJson($this->api('auth/login'), [
            'email' => 'ADMIN@factory.test',
            'password' => 'Password@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'admin@factory.test')
            ->assertJsonPath('data.roles.0.name', 'Admin')
            ->assertJsonFragment(['users.create'])
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'actor_id' => $admin->id]);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->createAdmin(['email' => 'admin@factory.test']);

        $this->postJson($this->api('auth/login'), [
            'email' => 'admin@factory.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();

        $log = ActivityLog::where('action', 'auth.login_failed')->sole();
        $this->assertNull($log->actor_id);
        $this->assertSame(['email' => 'admin@factory.test'], $log->metadata);
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $this->createAdmin(['email' => 'admin@factory.test']);
        RateLimiter::clear('login:admin@factory.test|127.0.0.1');

        foreach (range(1, 5) as $attempt) {
            $this->postJson($this->api('auth/login'), ['email' => 'admin@factory.test', 'password' => 'nope'])
                ->assertUnprocessable();
        }

        // Even the correct password is refused while locked out.
        $this->postJson($this->api('auth/login'), ['email' => 'admin@factory.test', 'password' => 'Password@123'])
            ->assertStatus(429);

        $this->assertSame(5, ActivityLog::where('action', 'auth.login_failed')->count());
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['email' => 'gone@factory.test']);

        $this->postJson($this->api('auth/login'), [
            'email' => 'gone@factory.test',
            'password' => 'Password@123',
        ])->assertForbidden();

        $this->assertGuest();
    }

    public function test_inactive_user_with_existing_session_is_blocked(): void
    {
        $user = $this->createUserWithRole('Store Keeper');
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->getJson($this->api('auth/me'))->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_guests_cannot_access_protected_endpoints(): void
    {
        $this->getJson($this->api('auth/me'))->assertUnauthorized();
        $this->getJson($this->api('users'))->assertUnauthorized();
        $this->getJson($this->api('dashboard'))->assertUnauthorized();
    }

    public function test_me_returns_effective_permissions(): void
    {
        $user = $this->createUserWithRole('QC');

        $this->actingAs($user)->getJson($this->api('auth/me'))
            ->assertOk()
            ->assertJsonPath('data.company.name', config('erp.company.default_name'))
            ->assertJsonFragment(['quality-control.approve'])
            ->assertJsonMissing(['users.view']);
    }

    public function test_inactive_role_grants_no_permissions(): void
    {
        $user = $this->createUserWithPermissions(['users.view']);
        $this->actingAs($user)->getJson($this->api('users'))->assertOk();

        $user->roles->first()->update(['is_active' => false]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh())->getJson($this->api('users'))->assertForbidden();
    }

    public function test_user_can_log_out(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->postJson($this->api('auth/logout'))->assertOk();

        $this->assertGuest('web');
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.logout', 'actor_id' => $admin->id]);
    }

    public function test_user_can_update_own_profile_but_not_privileged_fields(): void
    {
        $user = $this->createUserWithRole('Accountant', ['email' => 'acc@factory.test']);

        $this->actingAs($user)->putJson($this->api('auth/profile'), [
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'mobile' => '+91 98765 43210',
            'email' => 'hijack@factory.test',
            'is_active' => false,
            'role_ids' => [1],
        ])->assertOk()->assertJsonPath('data.first_name', 'Asha');

        $user->refresh();
        $this->assertSame('acc@factory.test', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertSame(['Accountant'], $user->getRoleNames()->all());
    }

    public function test_spa_shell_and_public_registration(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()->assertSee('<div id="root"></div>', false);
        // There is no registration endpoint.
        $this->postJson($this->api('auth/register'), [])->assertNotFound();
    }
}
