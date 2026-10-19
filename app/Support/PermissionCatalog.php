<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads the permission catalogue from config/erp.php and keeps the
 * permissions table and the primary admin role in sync with it.
 */
class PermissionCatalog
{
    public const GUARD = 'web';

    /** @return array<string, array{label: string, group: string, actions: list<string>}> */
    public function modules(): array
    {
        return config('erp.modules', []);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->modules() as $module => $definition) {
            foreach ($definition['actions'] as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return $names;
    }

    /**
     * Expand patterns such as "*" or "inventory.*" into concrete names.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function expand(array $patterns): array
    {
        $all = $this->names();

        return collect($patterns)
            ->flatMap(function (string $pattern) use ($all) {
                if ($pattern === '*') {
                    return $all;
                }
                if (str_ends_with($pattern, '.*')) {
                    $prefix = substr($pattern, 0, -1);

                    return array_filter($all, fn ($name) => str_starts_with($name, $prefix));
                }

                return in_array($pattern, $all, true) ? [$pattern] : [];
            })
            ->unique()->values()->all();
    }

    /**
     * Create any missing permissions and grant all of them to the primary
     * admin role. Never deletes permissions (they may be referenced).
     *
     * @return int number of permissions created
     */
    public function sync(): int
    {
        $created = 0;

        DB::transaction(function () use (&$created) {
            foreach ($this->names() as $name) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
                $created += $permission->wasRecentlyCreated ? 1 : 0;
            }

            Role::primaryAdmin()?->syncPermissions(
                Permission::where('guard_name', self::GUARD)->whereIn('name', $this->names())->get()
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $created;
    }
}
