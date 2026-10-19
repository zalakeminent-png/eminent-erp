<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetupCommandsTest extends TestCase
{
    public function test_create_admin_command_creates_first_administrator(): void
    {
        $this->artisan('erp:create-admin', ['--email' => 'Owner@Factory.test', '--first-name' => 'Plant', '--last-name' => 'Owner'])
            ->expectsQuestion('Password (min 10 chars, upper/lower case, number, symbol)', 'Str0ng#Passw0rd')
            ->expectsQuestion('Confirm password', 'Str0ng#Passw0rd')
            ->assertSuccessful();

        $user = User::where('email', 'owner@factory.test')->sole();
        $this->assertTrue($user->hasRole('Admin'));
        $this->assertTrue(Hash::check('Str0ng#Passw0rd', $user->password));

        // A second run is refused without --force.
        $this->artisan('erp:create-admin', ['--email' => 'x@factory.test', '--first-name' => 'X', '--last-name' => 'Y'])
            ->assertFailed();
    }

    public function test_create_admin_rejects_weak_password(): void
    {
        $this->artisan('erp:create-admin', ['--email' => 'owner@factory.test', '--first-name' => 'P', '--last-name' => 'O'])
            ->expectsQuestion('Password (min 10 chars, upper/lower case, number, symbol)', 'weak')
            ->expectsQuestion('Confirm password', 'weak')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@factory.test']);
    }

    public function test_seeder_is_idempotent_and_preserves_admin_edits(): void
    {
        $qc = Role::findByName('QC');
        $qc->syncPermissions(['dashboard.view']);

        $this->seed();

        $this->assertSame(['dashboard.view'], $qc->fresh()->permissions->pluck('name')->all());
        $this->assertSame(5, Role::count());
        $this->assertSame(count(app(PermissionCatalog::class)->names()), Permission::count());
        $this->assertSame(Permission::count(), Role::primaryAdmin()->permissions()->count());
    }

    public function test_catalog_expands_wildcards(): void
    {
        $catalog = app(PermissionCatalog::class);

        $this->assertSame(['reports.view', 'reports.export'], $catalog->expand(['reports.*']));
        $this->assertSame([], $catalog->expand(['unknown.view']));
        $this->assertCount(count($catalog->names()), $catalog->expand(['*']));
    }
}
