<?php

namespace Tests\Feature\Structure;

use App\Models\Department;
use App\Models\Factory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Tests\TestCase;

abstract class StructureTestCase extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
    }

    protected function makeFactory(array $attributes = []): Factory
    {
        return Factory::factory()->create($attributes);
    }

    protected function makeDepartment(Factory $factory, array $attributes = []): Department
    {
        $department = new Department(array_merge([
            'factory_id' => $factory->id,
            'code' => strtoupper(fake()->unique()->bothify('D##??')),
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ], $attributes));
        $department->manager_id = $attributes['manager_id'] ?? null;
        $department->supervisor_id = $attributes['supervisor_id'] ?? null;
        $department->save();

        return $department;
    }

    protected function makeWarehouse(Factory $factory, array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'factory_id' => $factory->id,
            'code' => strtoupper(fake()->unique()->bothify('W##??')),
            'name' => fake()->unique()->words(2, true).' Store',
            'is_active' => true,
        ], $attributes));
    }

    protected function makeLocation(Warehouse $warehouse, ?WarehouseLocation $parent = null, array $attributes = []): WarehouseLocation
    {
        return WarehouseLocation::create(array_merge([
            'warehouse_id' => $warehouse->id,
            'parent_id' => $parent?->id,
            'code' => strtoupper(fake()->unique()->bothify('L##??')),
            'name' => 'Location '.fake()->unique()->numberBetween(1, 99999),
            'type' => 'rack',
            'is_active' => true,
        ], $attributes));
    }

    /** A user restricted to the given factory (no factories.access-all). */
    protected function scopedUser(Factory $factory, array $permissions): User
    {
        $user = $this->createUserWithPermissions($permissions);
        $user->forceFill(['factory_id' => $factory->id])->save();

        return $user->fresh();
    }
}
