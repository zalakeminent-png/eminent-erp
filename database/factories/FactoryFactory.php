<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Factory;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;

/**
 * @extends EloquentFactory<Factory>
 */
class FactoryFactory extends EloquentFactory
{
    protected $model = Factory::class;

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::current()?->id ?? Company::factory(),
            'code' => strtoupper(fake()->unique()->bothify('F##??')),
            'name' => fake()->city().' Works',
            'type' => 'manufacturing',
            'country' => 'India',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
