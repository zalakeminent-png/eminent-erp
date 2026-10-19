<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * Creates the single operating company if none exists. Details are then
 * completed by an administrator on the Company Settings screen.
 */
class CompanySeeder extends Seeder
{
    public function run(): void
    {
        if (Company::query()->exists()) {
            return;
        }

        Company::create([
            'name' => config('erp.company.default_name'),
            'code' => config('erp.company.default_code'),
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'financial_year_start_month' => 4,
            'financial_year_end_month' => 3,
            'is_active' => true,
        ]);
    }
}
