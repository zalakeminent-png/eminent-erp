<?php

namespace Tests\Feature\MasterData;

use App\Models\MasterData\BusinessPartner;
use App\Models\MasterData\ImportBatch;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImportTest extends MasterDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(array $rows): UploadedFile
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);

        return UploadedFile::fake()->createWithContent('import.csv', stream_get_contents($out));
    }

    private function upload(string $entity, array $rows): array
    {
        return $this->actingAs($this->admin)->post($this->api('imports'), ['entity' => $entity, 'file' => $this->csv($rows)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    private function validateBatch(array $batch, bool $update = false): array
    {
        return $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/validate"), [
            'mapping' => $batch['mapping'], 'update_existing' => $update,
        ])->assertOk()->json('data');
    }

    public function test_duplicate_excel_rows_are_detected(): void
    {
        $this->makeItem('RM-EXISTING-001');

        $batch = $this->upload('items', [
            ['Item Code / SKU', 'Item Name', 'Category Code', 'Base UOM', 'Material Grade Code'],
            ['RM-SS316-101', 'SS316 Bar 20mm', 'RM', 'KG', 'SS316'],
            ['RM-SS316-102', 'SS316 Bar 25mm', 'RM', 'KG', 'SS316'],
            ['rm-ss316-101', 'Duplicate of first', 'RM', 'KG', ''],
            ['RM-EXISTING-001', 'Already in DB', 'RM', 'KG', ''],
        ]);
        $this->assertSame('Item Code / SKU', $batch['mapping']['code']); // auto-mapped
        $this->assertSame(4, $batch['total_rows']);

        $result = $this->validateBatch($batch);
        $this->assertSame(1, $result['create_count']);
        $this->assertSame(3, $result['error_rows']);
        $errors = collect($result['errors'])->keyBy('row');
        $this->assertStringContainsString('Duplicate', $errors[2]['errors'][0]);
        $this->assertStringContainsString('Duplicate', $errors[4]['errors'][0]);
        $this->assertStringContainsString('already exists', $errors[5]['errors'][0]);

        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true])
            ->assertOk()->assertJsonPath('data.created_count', 1)->assertJsonPath('data.skipped_count', 3);
        $this->assertTrue(Item::where('code', 'RM-SS316-102')->exists());
        $this->assertFalse(Item::where('code', 'RM-SS316-101')->exists());

        $report = $this->actingAs($this->admin)->get($this->api("imports/{$batch['id']}/errors"));
        $report->assertOk();
        $this->assertStringContainsString('Duplicate', file_get_contents($report->getFile()->getPathname()));
    }

    public function test_invalid_excel_imports_do_not_corrupt_existing_data(): void
    {
        $existing = $this->makeItem('RM-SS304-001', 'RM', 'KG', ['name' => 'Original name']);

        $batch = $this->upload('items', [
            ['Item Code / SKU', 'Item Name', 'Category Code', 'Base UOM'],
            ['RM-SS304-001', 'Overwritten name', 'RM', 'KG'],
            ['RM-NEW-001', 'Bad unit', 'RM', 'XYZ'],
            ['RM-NEW-002', '', 'NOPE', 'KG'],
        ]);

        // Without "update existing", the existing item is an error, not an update.
        $result = $this->validateBatch($batch);
        $this->assertSame(0, $result['valid_rows']);
        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true])->assertUnprocessable();

        // With updates enabled, confirmation of the updates is still required.
        $result = $this->validateBatch($batch, update: true);
        $this->assertSame(1, $result['update_count']);
        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true])
            ->assertJsonValidationErrors('confirm_updates');
        $this->assertSame('Original name', $existing->fresh()->name);

        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true, 'confirm_updates' => true])
            ->assertOk()->assertJsonPath('data.updated_count', 1)->assertJsonPath('data.created_count', 0);
        $this->assertSame('Overwritten name', $existing->fresh()->name);
        $this->assertSame(1, Item::count()); // invalid rows were not written
        $this->assertDatabaseHas('activity_logs', ['action' => 'import.completed']);

        // A completed batch cannot be committed again.
        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true, 'confirm_updates' => true])->assertUnprocessable();
    }

    public function test_category_import_resolves_parents_in_file_order(): void
    {
        $batch = $this->upload('item-categories', [
            ['Category Code', 'Category Name', 'Parent Category Code', 'Active'],
            ['RM-METAL', 'Metals', 'RM', 'Yes'],
            ['RM-SS', 'Stainless', 'RM-METAL', 'Yes'],
            ['ORPHAN', 'Orphan', 'MISSING', 'Yes'],
            ['BAD', 'Bad active', '', 'maybe'],
        ]);
        $result = $this->validateBatch($batch);
        $this->assertSame(2, $result['create_count']);

        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true])->assertOk();
        $this->assertSame('RM-METAL', ItemCategory::where('code', 'RM-SS')->sole()->parent->code);
    }

    public function test_customer_import_adds_role_to_existing_supplier(): void
    {
        $supplier = BusinessPartner::create(['code' => 'ACME', 'name' => 'Acme', 'is_supplier' => true, 'is_customer' => false, 'currency' => 'INR']);

        $batch = $this->upload('customers', [
            ['Party Code', 'Party Name', 'Billing Address Line 1', 'Billing City'],
            ['ACME', 'Acme Metals', 'Plot 1', 'Pune'],
            ['', 'New Customer Co', '', ''],
        ]);
        $this->validateBatch($batch, update: true);
        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/commit"), ['confirm' => true, 'confirm_updates' => true])->assertOk()
            ->assertJsonPath('data.created_count', 1)->assertJsonPath('data.updated_count', 1);

        $this->assertSame('both', $supplier->fresh()->partyType());
        $this->assertSame('customer', BusinessPartner::where('name', 'New Customer Co')->sole()->partyType());
    }

    public function test_import_permissions_mapping_and_templates(): void
    {
        $storeKeeper = $this->createUserWithRole('Store Keeper');
        $this->actingAs($storeKeeper)->getJson($this->api('imports'))->assertForbidden();

        // master-data.import alone is not enough to import items.
        $importer = $this->createUserWithPermissions(['master-data.import', 'material-grades.create']);
        $this->actingAs($importer)->getJson($this->api('imports/types'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entity', 'material-grades');
        $this->actingAs($importer)->post($this->api('imports'), ['entity' => 'items', 'file' => $this->csv([['a'], ['b']])], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($this->admin)->get($this->api('imports/templates/items'))->assertOk();

        // Required columns must be mapped; unknown headings rejected.
        $batch = $this->upload('material-grades', [['Code', 'Name', 'Family'], ['CW617N', 'Brass CW617N', 'BRASS']]);
        $this->actingAs($this->admin)->postJson($this->api("imports/{$batch['id']}/validate"), [
            'mapping' => ['code' => 'Code', 'name' => null, 'family_code' => 'Nope'], 'update_existing' => false,
        ])->assertJsonValidationErrors(['mapping.name', 'mapping.family_code']);

        $this->assertSame(ImportBatch::UPLOADED, ImportBatch::find($batch['id'])->status);
    }
}
