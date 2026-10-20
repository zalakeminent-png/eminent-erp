<?php

namespace Tests\Feature\MasterData;

use App\Models\MasterData\Bom;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemAttribute;
use App\Models\MasterData\ItemRevision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ItemMasterTest extends MasterDataTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'fg-stud-001',
            'name' => 'SS316 Tactile Stud 25mm',
            'category_id' => $this->category('FG')->id,
            'material_grade_id' => $this->grade('SS316')->id,
            'base_uom_id' => $this->uom('PCS')->id,
            'diameter' => '25',
            'height' => '5.5',
            'dimension_uom_id' => $this->uom('MM')->id,
            'weight_per_piece' => '12.75',
            'weight_uom_id' => $this->uom('GM')->id,
            'drawing_number' => 'DRG-1001',
            'drawing_revision' => 'A',
            'is_manufactured' => true,
            'is_sold' => true,
            'requires_qc' => true,
        ], $overrides);
    }

    public function test_admin_can_create_an_item(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'FG-STUD-001')
            ->assertJsonPath('data.item_type', 'finished_good') // inherited from the category
            ->assertJsonPath('data.diameter', '25')
            ->assertJsonPath('data.weight_per_piece', '12.75')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.allow_negative_stock', false);

        $item = Item::where('code', 'FG-STUD-001')->sole();
        $this->assertSame($this->admin->id, $item->created_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'item.created', 'entity_id' => $item->id]);
        // No stock balance columns exist on items.
        $this->assertFalse(Schema::hasColumn('items', 'quantity_on_hand'));
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload())->assertCreated();

        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['code' => 'FG-STUD-001', 'name' => 'Other']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'already exists']);
    }

    public function test_sku_is_generated_when_left_blank(): void
    {
        $first = $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['code' => '', 'category_id' => $this->category('RM')->id, 'base_uom_id' => $this->uom('KG')->id, 'is_manufactured' => false]))
            ->assertCreated()->json('data.code');
        $second = $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['code' => null, 'name' => 'Second', 'category_id' => $this->category('RM')->id, 'base_uom_id' => $this->uom('KG')->id]))
            ->assertCreated()->json('data.code');
        $noGrade = $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['code' => '', 'name' => 'Carton', 'category_id' => $this->category('PK')->id, 'material_grade_id' => null, 'base_uom_id' => $this->uom('PCS')->id]))
            ->assertCreated()->json('data.code');

        $this->assertSame('RM-SS316-001', $first);
        $this->assertSame('RM-SS316-002', $second);
        $this->assertSame('PK-001', $noGrade);
    }

    public function test_inactive_categories_cannot_be_assigned_to_new_items(): void
    {
        $category = $this->category('CON');
        $category->update(['is_active' => false]);

        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['category_id' => $category->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id' => 'active category']);
    }

    public function test_item_access_is_permission_controlled(): void
    {
        $item = $this->finishedGood();
        $storeKeeper = $this->createUserWithRole('Store Keeper'); // seeded default: items.view only

        $this->actingAs($storeKeeper)->getJson($this->api('items'))->assertOk()->assertJsonPath('data.0.code', $item->code);
        $this->actingAs($storeKeeper)->getJson($this->api("items/{$item->id}"))->assertOk();
        $this->actingAs($storeKeeper)->postJson($this->api('items'), $this->payload(['code' => 'X1']))->assertForbidden();
        $this->actingAs($storeKeeper)->putJson($this->api("items/{$item->id}"), $this->payload())->assertForbidden();
        $this->actingAs($storeKeeper)->patchJson($this->api("items/{$item->id}/status"), ['is_active' => false])->assertForbidden();
        $this->actingAs($storeKeeper)->get($this->api('items/export'))->assertForbidden();

        $nobody = $this->createUserWithPermissions(['dashboard.view']);
        $this->actingAs($nobody)->getJson($this->api('items'))->assertForbidden();
        $this->actingAs($nobody)->getJson($this->api('item-categories'))->assertForbidden();
    }

    public function test_specification_changes_create_revision_history(): void
    {
        $id = $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload())->json('data.id');

        $this->actingAs($this->admin)->putJson($this->api("items/{$id}"), $this->payload(['code' => 'FG-STUD-001', 'drawing_revision' => 'B', 'diameter' => '25.5', 'revision_reason' => 'ECN-12']))
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.diameter', '25.5');

        // Non-specification change: no new revision.
        $this->actingAs($this->admin)->putJson($this->api("items/{$id}"), $this->payload(['code' => 'FG-STUD-001', 'drawing_revision' => 'B', 'diameter' => '25.5', 'is_sold' => false]))
            ->assertOk()->assertJsonPath('data.revision', 2);

        $revisions = $this->actingAs($this->admin)->getJson($this->api("items/{$id}/revisions"))->assertOk()->json('data');
        $this->assertCount(2, $revisions);
        $this->assertSame(['from' => 'A', 'to' => 'B'], $revisions[0]['changes']['drawing_revision']);
        $this->assertSame('ECN-12', $revisions[0]['reason']);
        $this->assertSame(2, ItemRevision::where('item_id', $id)->count());
    }

    public function test_structural_fields_lock_once_referenced_by_approved_documents(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->makeItem('RM-SS316-001');
        $bom = Bom::forceCreate([
            'bom_number' => 'BOM-T1', 'item_id' => $fg->id, 'version' => 1, 'output_qty' => 1000, 'output_uom_id' => $fg->base_uom_id,
            'effective_from' => now()->toDateString(), 'status' => 'approved', 'approved_at' => now(),
        ]);
        $bom->lines()->create(['line_no' => 1, 'component_item_id' => $rm->id, 'quantity' => 25, 'uom_id' => $this->uom('KG')->id]);

        $this->actingAs($this->admin)->putJson($this->api("items/{$rm->id}"), [
            'code' => $rm->code, 'name' => $rm->name, 'category_id' => $rm->category_id, 'base_uom_id' => $this->uom('GM')->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['base_uom_id' => 'approved BOMs']);

        $this->actingAs($this->admin)->getJson($this->api("items/{$rm->id}"))
            ->assertJsonPath('meta.locked_fields', ['code', 'base_uom_id', 'item_type']);

        // Deactivation (soft) is still possible.
        $this->actingAs($this->admin)->patchJson($this->api("items/{$rm->id}/status"), ['is_active' => false])->assertOk();
    }

    public function test_item_specific_box_to_pcs_conversion_works(): void
    {
        $fg = $this->finishedGood();

        $this->actingAs($this->admin)->postJson($this->api("items/{$fg->id}/uom-conversions"), [
            'uom_id' => $this->uom('BOX')->id, 'factor' => '100', 'remarks' => 'Standard carton of 100',
        ])->assertCreated()->assertJsonPath('data.factor', '100');

        $this->actingAs($this->admin)->getJson($this->api("uom-conversions/convert?quantity=3&from=BOX&to=PCS&item_id={$fg->id}"))
            ->assertOk()->assertJsonPath('data.quantity', '300')->assertJsonPath('data.factor', '100');
        $this->actingAs($this->admin)->getJson($this->api("uom-conversions/convert?quantity=200&from=PCS&to=BOX&item_id={$fg->id}"))
            ->assertOk()->assertJsonPath('data.quantity', '2');
        // 250 PCS = 2.5 BOX, but BOX allows 0 decimals: refused, never silently rounded.
        $this->actingAs($this->admin)->getJson($this->api("uom-conversions/convert?quantity=250&from=PCS&to=BOX&item_id={$fg->id}"))
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity' => 'exceeds']);
    }

    public function test_item_conversion_rules(): void
    {
        $fg = $this->finishedGood();

        // Without an item, BOX → PCS is not convertible at all.
        $this->actingAs($this->admin)->getJson($this->api('uom-conversions/convert?quantity=1&from=BOX&to=PCS'))->assertUnprocessable();

        // Same-dimension units already convert universally (KG base, GM alternate).
        $rm = $this->makeItem('RM-1');
        $this->actingAs($this->admin)->postJson($this->api("items/{$rm->id}/uom-conversions"), ['uom_id' => $this->uom('GM')->id, 'factor' => '0.001'])
            ->assertJsonValidationErrors(['factor' => 'already converts universally']);

        // Zero factor and base-unit duplicates are rejected.
        $this->actingAs($this->admin)->postJson($this->api("items/{$fg->id}/uom-conversions"), ['uom_id' => $this->uom('BOX')->id, 'factor' => '0'])
            ->assertJsonValidationErrors('factor');
        $this->actingAs($this->admin)->postJson($this->api("items/{$fg->id}/uom-conversions"), ['uom_id' => $this->uom('PCS')->id, 'factor' => '1'])
            ->assertJsonValidationErrors('factor');

        // Documented weight per piece: 1 KG = 80 PCS, then GM resolves through KG.
        $this->actingAs($this->admin)->postJson($this->api("items/{$fg->id}/uom-conversions"), ['uom_id' => $this->uom('KG')->id, 'factor' => '80', 'remarks' => '12.5 g per piece'])
            ->assertCreated();
        $this->actingAs($this->admin)->getJson($this->api("uom-conversions/convert?quantity=500&from=GM&to=PCS&item_id={$fg->id}"))
            ->assertOk()->assertJsonPath('data.quantity', '40');

        // A contradictory second path (GM directly) is rejected.
        $this->actingAs($this->admin)->postJson($this->api("items/{$fg->id}/uom-conversions"), ['uom_id' => $this->uom('GM')->id, 'factor' => '0.1'])
            ->assertJsonValidationErrors('factor');
    }

    public function test_purchase_unit_must_be_convertible_to_base_unit(): void
    {
        $payload = $this->payload(['purchase_uom_id' => $this->uom('BOX')->id]);
        $this->actingAs($this->admin)->postJson($this->api('items'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_uom_id');
        $this->assertSame(0, Item::count());

        $payload['uom_conversions'] = [['uom_id' => $this->uom('BOX')->id, 'factor' => '100']];
        $this->actingAs($this->admin)->postJson($this->api('items'), $payload)->assertCreated()
            ->assertJsonPath('data.purchase_uom.code', 'BOX')
            ->assertJsonPath('data.uom_conversions.0.factor', '100');
    }

    public function test_item_validation(): void
    {
        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload([
            'name' => '', 'diameter' => '-5', 'dimension_uom_id' => $this->uom('KG')->id,
            'min_stock_level' => '10', 'max_stock_level' => '5', 'weight_uom_id' => $this->uom('MM')->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'diameter', 'dimension_uom_id', 'max_stock_level', 'weight_uom_id']);

        // Stock levels respect the base unit precision (PCS = 0 decimals).
        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['reorder_level' => '10.5']))
            ->assertJsonValidationErrors('reorder_level');
    }

    public function test_configurable_attributes_per_category(): void
    {
        $attribute = ItemAttribute::create(['code' => 'DOME', 'name' => 'Dome Pattern', 'data_type' => 'select', 'options' => ['Linear', 'Offset'], 'is_active' => true]);
        $this->actingAs($this->admin)->putJson($this->api('item-categories/'.$this->category('FG')->id), [
            'code' => 'FG', 'name' => 'Finished Goods', 'attributes' => [['attribute_id' => $attribute->id, 'is_required' => true]],
        ])->assertOk();

        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload())
            ->assertJsonValidationErrors("attributes.{$attribute->id}");
        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['attributes' => [$attribute->id => 'Diagonal']]))
            ->assertJsonValidationErrors("attributes.{$attribute->id}");
        $this->actingAs($this->admin)->postJson($this->api('items'), $this->payload(['attributes' => [$attribute->id => 'Offset']]))
            ->assertCreated()->assertJsonPath('data.attribute_values.0.value', 'Offset');
    }

    public function test_documents_upload_and_authorized_download(): void
    {
        Storage::fake('local');
        $item = $this->finishedGood();

        $this->actingAs($this->admin)->post($this->api("items/{$item->id}/documents"), [
            'category' => 'drawing', 'revision' => 'A', 'file' => UploadedFile::fake()->create('drawing.exe', 10),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');

        $docId = $this->actingAs($this->admin)->post($this->api("items/{$item->id}/documents"), [
            'category' => 'drawing', 'revision' => 'A', 'file' => UploadedFile::fake()->create('stud.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->actingAs($this->admin)->get($this->api("documents/{$docId}/download"))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->createUserWithPermissions(['boms.view']))->get($this->api("documents/{$docId}/download"))->assertForbidden();
        $this->actingAs($this->createUserWithRole('Store Keeper'))->deleteJson($this->api("documents/{$docId}"))->assertForbidden();
        $this->actingAs($this->admin)->deleteJson($this->api("documents/{$docId}"))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'document.deleted']);
    }

    public function test_items_list_search_filters_and_export(): void
    {
        $this->finishedGood('FG-STUD-001');
        $this->makeItem('RM-SS316-001', 'RM', 'KG', ['material_grade_id' => $this->grade('SS316')->id, 'drawing_number' => 'X-77']);
        $this->makeItem('RM-SS304-001', 'RM', 'KG', ['is_active' => false]);

        $this->actingAs($this->admin)->getJson($this->api('items?search=x-77'))->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson($this->api('items?category_id='.$this->category('RM')->id))->assertJsonPath('meta.total', 2);
        $this->actingAs($this->admin)->getJson($this->api('items?status=inactive'))->assertJsonPath('data.0.code', 'RM-SS304-001');
        $this->actingAs($this->admin)->getJson($this->api('items?sort=code&direction=desc&per_page=5'))->assertJsonPath('data.0.code', 'RM-SS316-001');

        $response = $this->actingAs($this->admin)->get($this->api('items/export?format=csv'));
        $response->assertOk();
        $this->assertStringContainsString('FG-STUD-001', file_get_contents($response->getFile()->getPathname()));
    }
}
