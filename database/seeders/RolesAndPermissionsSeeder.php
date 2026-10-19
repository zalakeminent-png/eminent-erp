<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to re-run. Default permissions are only applied to a
 * core role when it is first created, so administrator edits survive.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(PermissionCatalog $catalog): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($catalog->names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => PermissionCatalog::GUARD]);
        }

        foreach (config('erp.default_roles') as $name => $definition) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => PermissionCatalog::GUARD],
                ['description' => $definition['description'], 'is_system' => true, 'is_active' => true],
            );

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($catalog->expand($definition['permissions']));
            }
        }

        // Ensures the primary admin role always holds every permission.
        $catalog->sync();
    }
}
