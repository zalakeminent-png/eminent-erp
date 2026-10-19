<?php

namespace Tests\Feature\Structure;

use App\Models\StructureType;
use Illuminate\Support\Facades\DB;

class StructureTypeSortOrderTest extends StructureTestCase
{
    private function orders(string $category): array
    {
        return StructureType::where('category', $category)->orderBy('sort_order')->pluck('sort_order', 'name')->all();
    }

    public function test_seeded_types_use_sequential_sort_order(): void
    {
        $this->assertSame(range(1, 13), array_values($this->orders('department')));
        $this->assertSame(range(1, 9), array_values($this->orders('warehouse')));
        $this->assertSame(1, $this->orders('department')['Stores']);
        $this->assertSame(9, $this->orders('warehouse')['General Store']);
    }

    public function test_new_type_defaults_to_next_sequential_position(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('structure-types'), ['category' => 'department', 'name' => 'Heat Treatment'])
            ->assertCreated()->assertJsonPath('data.sort_order', 14);
        $this->actingAs($this->admin)->postJson($this->api('structure-types'), ['category' => 'warehouse', 'name' => 'Scrap Yard'])
            ->assertCreated()->assertJsonPath('data.sort_order', 10);

        // An explicit value is respected.
        $this->actingAs($this->admin)->postJson($this->api('structure-types'), ['category' => 'warehouse', 'name' => 'Tool Crib', 'sort_order' => 3])
            ->assertCreated()->assertJsonPath('data.sort_order', 3);
    }

    public function test_sort_order_can_be_edited_and_lists_ascending(): void
    {
        $administration = StructureType::where('category', 'department')->where('name', 'Administration')->sole();

        $this->actingAs($this->admin)->putJson($this->api("structure-types/{$administration->id}"), ['name' => 'Administration', 'sort_order' => 0])
            ->assertOk()->assertJsonPath('data.sort_order', 0);
        $this->assertSame(0, $administration->fresh()->sort_order);

        $names = collect($this->actingAs($this->admin)->getJson($this->api('structure-types?category=department'))->json('data'))->pluck('name');
        $this->assertSame('Administration', $names->first());
        $this->assertSame('Stores', $names->get(1));

        // An empty value keeps the existing position.
        $this->actingAs($this->admin)->putJson($this->api("structure-types/{$administration->id}"), ['name' => 'Administration', 'sort_order' => null])
            ->assertOk()->assertJsonPath('data.sort_order', 0);
    }

    public function test_migration_renumbers_old_values_without_duplicates(): void
    {
        // Simulate the old 10, 20, 30… scheme including a tie.
        StructureType::where('category', 'department')->get()->each(fn ($t) => $t->update(['sort_order' => $t->sort_order * 10]));
        StructureType::create(['category' => 'department', 'name' => 'Logistics', 'sort_order' => 90]);

        $migration = require database_path('migrations/2026_10_21_000001_renumber_structure_type_sort_order.php');
        $migration->up();

        $orders = $this->orders('department');
        $this->assertSame(range(1, 14), array_values($orders));
        $this->assertSame(1, $orders['Stores']);
        // Tie at 90 resolved by name, preserving the previous display order.
        $this->assertSame(9, $orders['Logistics']);
        $this->assertSame(10, $orders['Quality Control']);
        $this->assertSame(range(1, 9), array_values($this->orders('warehouse')));
        $this->assertSame(0, DB::table('structure_types')->select('category', 'sort_order')->groupBy('category', 'sort_order')->havingRaw('COUNT(*) > 1')->count());
    }
}
