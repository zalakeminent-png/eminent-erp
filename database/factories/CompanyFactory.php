<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' Private Limited',
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'email' => fake()->companyEmail(),
            'country' => 'India',
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'financial_year_start_month' => 4,
            'financial_year_end_month' => 3,
            'is_active' => true,
        ];
    }
}
