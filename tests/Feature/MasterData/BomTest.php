<?php

namespace Tests\Feature\MasterData;

use App\Models\MasterData\Bom;
use App\Models\MasterData\Item;
use Illuminate\Support\Facades\Schema;

class BomTest extends MasterDataTestCase
{
    private Item $product;

    private Item $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = $this->finishedGood('FG-STUD-001');
        $this->raw = $this->makeItem('RM-SS316-001', 'RM', 'KG');
    }

    private function payload(?array $lines = null, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $this->product->id,
            'output_qty' => '1000',
            'output_uom_id' => $this->uom('PCS')->id,
            'effective_from' => now()->toDateString(),
            'lines' => $lines ?? [['component_item_id' => $this->raw->id, 'quantity' => '12.5', 'uom_id' => $this->uom('KG')->id, 'scrap_percent' => '2.5']],
        ], $overrides);
    }

    private function createBom(?array $lines = null, array $overrides = []): int
    {
        return $this->actingAs($this->admin)->postJson($this->api('boms'), $this->payload($lines, $overrides))->assertCreated()->json('data.id');
    }

    public function test_admin_can_create_and_approve_a_bom(): void
    {
        $response = $this->actingAs($this->admin)->postJson($this->api('boms'), $this->payload());
        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.lines.0.quantity', '12.5')
            ->assertJsonPath('data.lines.0.scrap_percent', '2.5');
        $id = $response->json('data.id');

        $this->actingAs($this->admin)->postJson($this->api("boms/{$id}/approve"))
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by.id', $this->admin->id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'bom.approved', 'entity_id' => $id]);
        // BOM approval never touches stock (no stock tables exist in this module).
        $this->assertFalse(Schema::hasTable('stock_movements'));
    }

    public function test_store_keeper_cannot_approve_a_bom_without_permission(): void
    {
        $id = $this->createBom();

        $this->actingAs($this->createUserWithRole('Store Keeper'))->postJson($this->api("boms/{$id}/approve"))->assertForbidden();
        $manager = $this->createUserWithRole('Factory Manager'); // can create/update, not approve by default
        $this->actingAs($manager)->postJson($this->api("boms/{$id}/approve"))->assertForbidden();
        $this->actingAs($manager)->putJson($this->api("boms/{$id}"), collect($this->payload())->except('item_id')->all())->assertOk();

        $this->assertSame('draft', Bom::find($id)->status);
    }

    public function test_bom_cannot_contain_circular_dependencies(): void
    {
        // Self reference.
        $this->actingAs($this->admin)->postJson($this->api('boms'), $this->payload([
            ['component_item_id' => $this->product->id, 'quantity' => '1', 'uom_id' => $this->uom('PCS')->id],
        ]))->assertJsonValidationErrors(['lines.0.component_item_id' => 'circular']);

        // Indirect: SFG contains FG, then FG tries to contain SFG.
        $sfg = $this->makeItem('SFG-STUD-001', 'SFG', 'PCS', ['is_manufactured' => true]);
        $this->createBom([['component_item_id' => $this->product->id, 'quantity' => '1', 'uom_id' => $this->uom('PCS')->id]], ['item_id' => $sfg->id]);

        $this->actingAs($this->admin)->postJson($this->api('boms'), $this->payload([
            ['component_item_id' => $sfg->id, 'quantity' => '1', 'uom_id' => $this->uom('PCS')->id],
        ]))->assertJsonValidationErrors(['lines.0.component_item_id' => 'circular']);
    }

    public function test_component_uom_must_convert_to_its_base_unit(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('boms'), $this->payload([
            ['component_item_id' => $this->raw->id, 'quantity' => '5', 'uom_id' => $this->uom('PCS')->id],
        ]))->assertJsonValidationErrors(['lines.0.uom_id' => 'cannot be converted']);

        // GM converts to the KG base universally.
        $this->createBom([['component_item_id' => $this->raw->id, 'quantity' => '12500', 'uom_id' => $this->uom('GM')->id]]);

        // Precision: KG allows 3 decimals.
        $this->actingAs($this->admin)->postJson($this->api('boms'), $this->payload([
            ['component_item_id' => $this->raw->id, 'quantity' => '1.2345', 'uom_id' => $this->uom('KG')->id],
        ]))->assertJsonValidationErrors(['lines.0.quantity' => 'decimal places']);
    }

    public function test_bom_approval_freezes_the_approved_revision(): void
    {
        $id = $this->createBom();
        $this->actingAs($this->admin)->postJson($this->api("boms/{$id}/approve"))->assertOk();

        $this->actingAs($this->admin)->putJson($this->api("boms/{$id}"), collect($this->payload())->except('item_id')->merge(['output_qty' => '500'])->all())
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Create a revision']);
        $this->actingAs($this->admin)->deleteJson($this->api("boms/{$id}"))->assertUnprocessable();
        $this->actingAs($this->admin)->postJson($this->api("boms/{$id}/approve"))->assertUnprocessable();

        $bom = Bom::with('lines')->find($id);
        $this->assertSame('1000.000000', (string) $bom->output_qty);
        $this->assertCount(1, $bom->lines);
    }

    public function test_bom_revisions_preserve_history(): void
    {
        $v1 = $this->createBom();
        $this->actingAs($this->admin)->postJson($this->api("boms/{$v1}/approve"))->assertOk();

        $v2 = $this->actingAs($this->admin)->postJson($this->api("boms/{$v1}/revise"))
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revised_from.id', $v1)->assertJsonPath('data.lines.0.quantity', '12.5')->json('data.id');

        $this->actingAs($this->admin)->putJson($this->api("boms/{$v2}"), collect($this->payload([
            ['component_item_id' => $this->raw->id, 'quantity' => '11', 'uom_id' => $this->uom('KG')->id],
        ]))->except('item_id')->all())->assertOk();

        // Only one approved version may be effective for the same period.
        $this->actingAs($this->admin)->postJson($this->api("boms/{$v2}/approve"))->assertJsonValidationErrors(['approval']);
        $this->actingAs($this->admin)->patchJson($this->api("boms/{$v1}/status"), ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->actingAs($this->admin)->postJson($this->api("boms/{$v2}/approve"))->assertOk();

        // v1 still exists unchanged; v1 cannot be reactivated while v2 is effective.
        $this->assertSame('12.500000', (string) Bom::find($v1)->lines()->first()->quantity);
        $this->actingAs($this->admin)->patchJson($this->api("boms/{$v1}/status"), ['is_active' => true])->assertUnprocessable();
        $this->actingAs($this->admin)->getJson($this->api("boms?item_id={$this->product->id}"))->assertJsonPath('meta.total', 2);
        $this->assertDatabaseHas('activity_logs', ['action' => 'bom.revised', 'entity_id' => $v2]);
    }

    public function test_multi_level_structure_and_export(): void
    {
        $sfg = $this->makeItem('SFG-BLANK-001', 'SFG', 'PCS', ['is_manufactured' => true]);
        $sfgBom = $this->createBom([['component_item_id' => $this->raw->id, 'quantity' => '20', 'uom_id' => $this->uom('KG')->id]], ['item_id' => $sfg->id]);
        $this->actingAs($this->admin)->postJson($this->api("boms/{$sfgBom}/approve"))->assertOk();

        $id = $this->createBom([['component_item_id' => $sfg->id, 'quantity' => '1000', 'uom_id' => $this->uom('PCS')->id, 'scrap_percent' => '10']]);

        $this->actingAs($this->admin)->getJson($this->api("boms/{$id}/structure"))
            ->assertOk()
            ->assertJsonPath('data.components.0.item.code', 'SFG-BLANK-001')
            ->assertJsonPath('data.components.0.base_quantity_with_scrap', '1100')
            ->assertJsonPath('data.components.0.children.0.item.code', 'RM-SS316-001')
            // 1100 SFG need 1100/1000 × 20 KG = 22 KG.
            ->assertJsonPath('data.components.0.children.0.base_quantity_with_scrap', '22');

        $this->actingAs($this->createUserWithPermissions(['boms.view']))->get($this->api("boms/{$id}/export"))->assertForbidden();
        $this->actingAs($this->admin)->get($this->api("boms/{$id}/export?format=csv"))->assertOk();
    }
}
