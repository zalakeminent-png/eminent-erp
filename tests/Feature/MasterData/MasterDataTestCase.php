<?php

namespace Tests\Feature\MasterData;

use App\Models\Department;
use App\Models\Factory;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\Machine;
use App\Models\MasterData\ManufacturingProcess;
use App\Models\MasterData\MaterialGrade;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Services\MasterData\ItemService;
use Tests\TestCase;

abstract class MasterDataTestCase extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
    }

    protected function uom(string $code): UnitOfMeasure
    {
        return UnitOfMeasure::where('code', $code)->sole();
    }

    protected function category(string $code): ItemCategory
    {
        return ItemCategory::where('code', $code)->sole();
    }

    protected function grade(string $code): MaterialGrade
    {
        return MaterialGrade::where('code', $code)->sole();
    }

    protected function process(string $code): ManufacturingProcess
    {
        return ManufacturingProcess::where('code', $code)->sole();
    }

    /** Creates an item through the real service (validation rules aside). */
    protected function makeItem(string $code, string $categoryCode = 'RM', string $uom = 'KG', array $extra = []): Item
    {
        $this->actingAs($this->admin);

        return app(ItemService::class)->save(null, array_merge([
            'code' => $code,
            'name' => "Item {$code}",
            'category_id' => $this->category($categoryCode)->id,
            'base_uom_id' => $this->uom($uom)->id,
            'is_active' => true,
        ], $extra));
    }

    protected function finishedGood(string $code = 'FG-STUD-001'): Item
    {
        return $this->makeItem($code, 'FG', 'PCS', ['is_manufactured' => true]);
    }

    /** @return array{0: Factory, 1: Department} */
    protected function factoryAndDepartment(): array
    {
        $factory = Factory::factory()->create();
        $department = new Department(['factory_id' => $factory->id, 'code' => 'MACH-'.uniqid(), 'name' => 'Machining', 'is_active' => true]);
        $department->save();

        return [$factory, $department];
    }

    protected function machine(Department $department, array $processCodes, array $extra = []): Machine
    {
        $machine = Machine::create(array_merge([
            'factory_id' => $department->factory_id,
            'department_id' => $department->id,
            'code' => 'M-'.uniqid(),
            'name' => 'Machine',
            'is_active' => true,
        ], $extra));
        $machine->processes()->sync(collect($processCodes)->map(fn ($c) => $this->process($c)->id));

        return $machine;
    }
}
