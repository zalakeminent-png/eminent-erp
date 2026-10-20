<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. No users are seeded; create the
     * first administrator with `php artisan erp:create-admin`.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            RolesAndPermissionsSeeder::class,
            StructureSeeder::class,
            MasterDataSeeder::class,
        ]);
    }
}
