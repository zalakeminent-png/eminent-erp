<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\ManufacturingProcess;
use App\Models\MasterData\MaterialFamily;
use App\Models\MasterData\MaterialGrade;
use App\Models\MasterData\QcParameter;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\MasterData\UomConversion;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 3 setup. Idempotent and non-destructive: only missing records are
 * created; existing (possibly edited) records are never overwritten.
 */
class MasterDataSeeder extends Seeder
{
    public const ROLE_GRANTS_MARKER = 'module3.default-role-permissions';

    /**
     * Recommended Module 3 access per core role, applied ONCE. Later
     * changes made in the permission matrix are never reverted.
     */
    public const DEFAULT_ROLE_PERMISSIONS = [
        'Factory Manager' => [
            'master-data.view', 'item-categories.view', 'uoms.view', 'material-grades.view',
            'items.view', 'items.create', 'items.update',
            'processes.view', 'processes.create', 'processes.update',
            'machines.view', 'machines.create', 'machines.update',
            'boms.view', 'boms.create', 'boms.update',
            'routings.view', 'routings.create', 'routings.update',
            'qc-specifications.view', 'qc-specifications.create', 'qc-specifications.update',
        ],
        'Store Keeper' => ['master-data.view', 'items.view', 'item-categories.view', 'uoms.view', 'material-grades.view'],
        'Accountant' => ['master-data.view', 'items.view', 'item-categories.view', 'business-partners.view'],
        'QC' => ['master-data.view', 'items.view', 'routings.view', 'qc-specifications.view', 'qc-specifications.create', 'qc-specifications.update'],
    ];

    public function run(PermissionCatalog $catalog): void
    {
        $catalog->sync(); // creates new permissions; grants all to Admin only

        $company = Company::current();
        if (! $company) {
            return;
        }

        DB::transaction(function () use ($company) {
            $this->seedUoms();
            $this->seedCategories($company->id);
            $this->seedMaterials($company->id);
            $this->seedProcesses($company->id);
            $this->seedQcParameters($company->id);
        });

        $this->grantDefaultRolePermissions();
    }

    private function seedUoms(): void
    {
        foreach (config('erp.master_data.default_uoms') as [$code, $name, $dimension, $precision]) {
            UnitOfMeasure::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'dimension' => $dimension, 'decimal_precision' => $precision, 'is_active' => true,
            ])->forceFill(['is_system' => true])->saveQuietly();
        }

        $ids = UnitOfMeasure::query()->pluck('id', 'code');
        foreach (config('erp.master_data.default_uom_conversions') as [$from, $to, $factor]) {
            $exists = UomConversion::query()
                ->where(fn ($q) => $q->where('from_uom_id', $ids[$from])->where('to_uom_id', $ids[$to]))
                ->orWhere(fn ($q) => $q->where('from_uom_id', $ids[$to])->where('to_uom_id', $ids[$from]))
                ->exists();
            if (! $exists) {
                UomConversion::create(['from_uom_id' => $ids[$from], 'to_uom_id' => $ids[$to], 'factor' => $factor, 'is_active' => true]);
            }
        }
    }

    private function seedCategories(int $companyId): void
    {
        foreach (config('erp.master_data.default_categories') as $category) {
            $model = ItemCategory::query()->firstOrNew(['company_id' => $companyId, 'code' => $category['code']]);
            if (! $model->exists) {
                $model->fill([
                    'name' => $category['name'], 'sku_prefix' => $category['sku_prefix'],
                    'default_item_type' => $category['item_type'], 'is_active' => true,
                ]);
                $model->is_system = true;
                $model->save();
            }
        }
    }

    private function seedMaterials(int $companyId): void
    {
        foreach (config('erp.master_data.default_material_families') as [$code, $name]) {
            MaterialFamily::query()->firstOrCreate(['company_id' => $companyId, 'code' => $code], ['name' => $name, 'is_active' => true]);
        }
        $families = MaterialFamily::query()->where('company_id', $companyId)->pluck('id', 'code');
        foreach (config('erp.master_data.default_material_grades') as [$code, $name, $family]) {
            MaterialGrade::query()->firstOrCreate(['company_id' => $companyId, 'code' => $code], [
                'name' => $name, 'family_id' => $families[$family], 'is_active' => true,
            ]);
        }
    }

    private function seedProcesses(int $companyId): void
    {
        foreach (config('erp.master_data.default_processes') as [$code, $name, $type, $qc]) {
            ManufacturingProcess::query()->firstOrCreate(['company_id' => $companyId, 'code' => $code], [
                'name' => $name, 'process_type' => $type, 'requires_qc' => $qc, 'is_active' => true,
            ]);
        }
    }

    private function seedQcParameters(int $companyId): void
    {
        $uoms = UnitOfMeasure::query()->pluck('id', 'code');
        foreach (config('erp.master_data.default_qc_parameters') as [$code, $name, $type, $uom]) {
            QcParameter::query()->firstOrCreate(['company_id' => $companyId, 'code' => $code], [
                'name' => $name, 'data_type' => $type, 'default_uom_id' => $uom ? $uoms[$uom] : null,
                'options' => $type === 'selection' ? ['Matt', 'Satin', 'Mirror', 'Brushed'] : null,
                'is_active' => true,
            ]);
        }
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
