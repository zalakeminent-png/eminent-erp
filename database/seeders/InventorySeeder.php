<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Inventory\ReasonCode;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4 setup. Idempotent and non-destructive: creates missing
 * permissions and reason codes; never overwrites edited records and never
 * creates stock.
 */
class InventorySeeder extends Seeder
{
    public const ROLE_GRANTS_MARKER = 'module4.default-role-permissions';

    /**
     * Recommended Module 4 access per core role, applied ONCE. Later changes
     * made in the permission matrix are never reverted.
     */
    public const DEFAULT_ROLE_PERMISSIONS = [
        'Factory Manager' => [
            'inventory.dashboard.view', 'inventory.stock.view', 'inventory.wip.view', 'inventory.finished-goods.view',
            'inventory.ledger.view', 'inventory.reports.view', 'inventory.reports.export',
            'inventory.opening-stock.view', 'inventory.opening-stock.approve',
            'inventory.grn.view', 'inventory.transfers.view', 'inventory.transfers.approve',
            'inventory.material-issues.view', 'inventory.material-issues.approve',
            'inventory.returns.view', 'inventory.returns.approve',
            'inventory.qc-disposition.view', 'inventory.scrap.view', 'inventory.scrap.approve',
            'inventory.reservations.view', 'inventory.adjustments.view', 'inventory.adjustments.approve',
            'inventory.counts.view', 'inventory.counts.approve',
        ],
        'Store Keeper' => [
            'inventory.dashboard.view', 'inventory.stock.view', 'inventory.wip.view', 'inventory.finished-goods.view', 'inventory.ledger.view',
            'inventory.opening-stock.view', 'inventory.opening-stock.create', 'inventory.opening-stock.import',
            'inventory.grn.view', 'inventory.grn.create', 'inventory.grn.update', 'inventory.grn.post',
            'inventory.transfers.view', 'inventory.transfers.create', 'inventory.transfers.issue', 'inventory.transfers.receive',
            'inventory.material-issues.view', 'inventory.material-issues.create', 'inventory.material-issues.post',
            'inventory.returns.view', 'inventory.returns.create', 'inventory.returns.post',
            'inventory.scrap.view', 'inventory.scrap.create', 'inventory.qc-disposition.view',
            'inventory.reservations.view', 'inventory.reservations.create', 'inventory.reservations.release',
            'inventory.adjustments.view', 'inventory.adjustments.create',
            'inventory.counts.view', 'inventory.counts.create', 'inventory.counts.submit',
        ],
        'Accountant' => [
            'inventory.dashboard.view', 'inventory.stock.view', 'inventory.wip.view', 'inventory.finished-goods.view',
            'inventory.ledger.view', 'inventory.reports.view', 'inventory.reports.export',
            'inventory.opening-stock.view', 'inventory.grn.view', 'inventory.adjustments.view', 'inventory.counts.view',
        ],
        'QC' => [
            'inventory.dashboard.view', 'inventory.stock.view', 'inventory.grn.view', 'inventory.returns.view',
            'inventory.qc-disposition.view', 'inventory.qc-disposition.release', 'inventory.qc-disposition.hold', 'inventory.qc-disposition.reject',
            'inventory.scrap.view', 'inventory.scrap.create',
        ],
    ];

    public function run(PermissionCatalog $catalog): void
    {
        $catalog->sync(); // creates new permissions; grants all to Admin only

        if ($company = Company::current()) {
            DB::transaction(function () use ($company) {
                foreach (config('erp.inventory.default_reason_codes') as [$code, $name, $category]) {
                    ReasonCode::query()->firstOrCreate(
                        ['company_id' => $company->id, 'code' => $code],
                        ['name' => $name, 'category' => $category, 'is_active' => true],
                    );
                }
            });
        }

        $this->grantDefaultRolePermissions();
    }

    private function grantDefaultRolePermissions(): void
    {
        if (DB::table('seed_markers')->where('key', self::ROLE_GRANTS_MARKER)->exists()) {
            return;
        }

        DB::transaction(function () {
            foreach (self::DEFAULT_ROLE_PERMISSIONS as $roleName => $permissions) {
                // Adds only; never removes anything an administrator configured.
                Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
            }
            DB::table('seed_markers')->insert(['key' => self::ROLE_GRANTS_MARKER, 'applied_at' => now()]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
