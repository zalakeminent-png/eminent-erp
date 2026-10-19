<?php

namespace Database\Seeders;

use App\Models\StructureType;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Module 2 setup. Idempotent and non-destructive:
 *  - creates any missing permissions from config/erp.php and grants them to
 *    the primary admin role only (other roles are left untouched);
 *  - adds the suggested department/warehouse types if they do not exist.
 * No factories, departments or warehouses are created.
 */
class StructureSeeder extends Seeder
{
    public function run(PermissionCatalog $catalog): void
    {
        $catalog->sync();

        foreach (config('erp.structure.suggested_types') as $category => $names) {
            foreach (array_values($names) as $index => $name) {
                StructureType::firstOrCreate(
                    ['category' => $category, 'name' => $name],
                    ['is_active' => true, 'sort_order' => $index + 1],
                );
            }
        }
    }
}
