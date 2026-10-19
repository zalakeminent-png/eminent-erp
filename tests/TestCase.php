<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seed company, roles and permissions before each test. */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Mark requests as coming from the SPA so Sanctum treats them as
        // stateful (session cookie authentication).
        $this->withHeader('Referer', config('app.url'));
    }

    protected function api(string $path): string
    {
        return '/api/v1/'.ltrim($path, '/');
    }

    protected function createAdmin(array $attributes = []): User
    {
        return $this->createUserWithRole(config('erp.primary_admin_role'), $attributes);
    }

    protected function createUserWithRole(string $roleName, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(Role::findByName($roleName, 'web'));

        return $user->fresh();
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function createUserWithPermissions(array $permissions, array $attributes = []): User
    {
        $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user->fresh();
    }
}
