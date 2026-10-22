<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stock management permissions. Idempotent and additive: creates missing
 * permissions, gives them to Admin, and grants the recommended access to
 * the other core roles once (later matrix changes are never reverted).
 */
class StockSeeder extends Seeder
{
    public const MODULES = ['stock-overview', 'opening-stock', 'skus', 'vendors', 'vendor-skus', 'inward', 'outward'];

    public const ROLE_GRANTS_MARKER = 'stock.default-role-permissions';

    public const DEFAULT_ROLE_PERMISSIONS = [
        'Factory Manager' => [
            'stock-overview.view', 'stock-overview.export', 'opening-stock.view', 'opening-stock.post', 'opening-stock.reverse',
            'skus.view', 'vendors.view', 'vendor-skus.view',
            'inward.view', 'inward.post', 'inward.reverse', 'inward.export', 'inward.override-vendor-check',
            'outward.view', 'outward.post', 'outward.reverse', 'outward.export',
        ],
        'Store Keeper' => [
            'stock-overview.view', 'opening-stock.view', 'opening-stock.create', 'opening-stock.update',
            'skus.view', 'vendors.view', 'vendor-skus.view',
            'inward.view', 'inward.create', 'inward.update', 'inward.post',
            'outward.view', 'outward.create', 'outward.update', 'outward.post',
        ],
        'Accountant' => [
            'stock-overview.view', 'stock-overview.export', 'skus.view', 'vendors.view', 'vendor-skus.view',
            'inward.view', 'inward.export', 'outward.view', 'outward.export', 'opening-stock.view',
        ],
        'QC' => ['stock-overview.view', 'skus.view', 'inward.view'],
    ];

    public function run(PermissionCatalog $catalog): void
    {
        $names = collect($catalog->names())->filter(fn ($n) => in_array(explode('.', $n)[0], self::MODULES, true))->values();

        DB::transaction(function () use ($names) {
            $permissions = $names->map(fn ($n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => PermissionCatalog::GUARD]));
            Role::primaryAdmin()?->givePermissionTo($permissions);

            if (! DB::table('seed_markers')->where('key', self::ROLE_GRANTS_MARKER)->exists()) {
                foreach (self::DEFAULT_ROLE_PERMISSIONS as $roleName => $granted) {
                    Role::query()->where('name', $roleName)->where('guard_name', PermissionCatalog::GUARD)->first()?->givePermissionTo($granted);
                }
                DB::table('seed_markers')->insert(['key' => self::ROLE_GRANTS_MARKER, 'applied_at' => now()]);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
