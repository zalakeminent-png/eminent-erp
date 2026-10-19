<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    public function test_activity_logs_record_important_changes(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();

        $this->postJson($this->api('auth/login'), ['email' => $admin->email, 'password' => 'Password@123'])->assertOk();

        $this->postJson($this->api('users'), [
            'first_name' => 'Kiran', 'last_name' => 'Patil', 'email' => 'kiran@factory.test',
            'role_ids' => [Role::findByName('QC')->id],
        ])->assertCreated();
        $userId = \App\Models\User::where('email', 'kiran@factory.test')->value('id');

        $this->patchJson($this->api("users/{$userId}/status"), ['is_active' => false])->assertOk();
        $roleId = $this->postJson($this->api('roles'), ['name' => 'Auditor'])->json('data.id');
        $this->putJson($this->api("roles/{$roleId}/permissions"), ['permissions' => ['reports.view']])->assertOk();

        $actions = ActivityLog::orderBy('id')->pluck('action')->all();
        foreach (['auth.login', 'user.created', 'user.deactivated', 'role.created', 'role.permissions_updated'] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $log = ActivityLog::where('action', 'role.permissions_updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('role', $log->entity_type);
        $this->assertSame(['reports.view'], $log->metadata['added']);
        $this->assertNotNull($log->ip_address);

        $this->getJson($this->api('activity-logs?action=user.deactivated'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor.id', $admin->id);
    }

    public function test_sensitive_metadata_is_redacted(): void
    {
        $clean = app(ActivityLogger::class)->sanitize([
            'password' => 'secret', 'reset_token' => 'abc', 'nested' => ['api_key' => 'k', 'name' => 'ok'], 'email' => 'a@b.c',
        ]);

        $this->assertSame('[REDACTED]', $clean['password']);
        $this->assertSame('[REDACTED]', $clean['reset_token']);
        $this->assertSame('[REDACTED]', $clean['nested']['api_key']);
        $this->assertSame('ok', $clean['nested']['name']);
        $this->assertSame('a@b.c', $clean['email']);
    }

    public function test_logs_are_read_only_and_restricted(): void
    {
        $admin = $this->createAdmin();
        $log = app(ActivityLogger::class)->log('test.event', null, [], 'x', $admin);

        $this->actingAs($this->createUserWithRole('Store Keeper'))->getJson($this->api('activity-logs'))->assertForbidden();

        $this->actingAs($admin)->deleteJson($this->api("activity-logs/{$log->id}"))->assertStatus(404);
        $this->actingAs($admin)->postJson($this->api('activity-logs'), [])->assertStatus(405);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_retention_pruning(): void
    {
        $admin = $this->createAdmin();
        $old = app(ActivityLogger::class)->log('old.event', null, [], null, $admin);
        ActivityLog::query()->whereKey($old->id)->toBase()->update(['created_at' => now()->subDays(400)]);
        app(ActivityLogger::class)->log('new.event', null, [], null, $admin);

        // No retention configured: nothing is pruned.
        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'old.event']);

        config(['erp.activity_logs.retention_days' => 365]);
        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'old.event']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'new.event']);
    }

    public function test_dashboard_shows_real_counts(): void
    {
        $admin = $this->createAdmin();
        $this->createUserWithRole('QC', ['is_active' => false]);

        $this->actingAs($admin)->getJson($this->api('dashboard'))
            ->assertOk()
            ->assertJsonPath('data.stats.active_users', 1)
            ->assertJsonPath('data.stats.inactive_users', 1)
            ->assertJsonPath('data.stats.total_roles', 5);

        // Without activity-logs.view, recent activity is withheld.
        $this->actingAs($this->createUserWithRole('Factory Manager'))->getJson($this->api('dashboard'))
            ->assertOk()->assertJsonPath('data.recent_activity', null);
    }
}
