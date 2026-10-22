<?php

namespace Tests\Feature\Stock;

use App\Models\Stock\Sku;
use App\Models\Stock\Vendor;
use App\Models\Stock\VendorSku;
use Illuminate\Http\UploadedFile;

/** SKU Master, Vendor Master and vendor–SKU mapping. */
class MasterRecordsTest extends StockTestCase
{
    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('data.csv', $content);
    }

    public function test_sku_create_search_validate_and_deactivate(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson($this->api('skus'), ['sku' => 'ET-WTS001-316', 'product_name' => 'SS 316 Tactile Stud', 'category' => 'Stud', 'material' => '316', 'size' => '35MM', 'reorder_level' => '100'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->postJson($this->api('skus'), ['sku' => 'ET-WTS001-316', 'product_name' => 'Duplicate'])->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', 'This SKU code already exists.');
        $this->postJson($this->api('skus'), ['sku' => '', 'product_name' => ''])->assertJsonValidationErrors(['sku', 'product_name']);
        $this->sku('ET-DTS006-304', ['material' => '304', 'category' => 'Strip']);

        $this->assertSame(['ET-WTS001-316'], array_column($this->getJson($this->api('skus?search=tactile'))->json('data'), 'sku'));
        $this->assertSame(['ET-DTS006-304'], array_column($this->getJson($this->api('skus?material=304'))->json('data'), 'sku'));
        $this->getJson($this->api('skus'))->assertJsonPath('filters.categories', ['Strip', 'Stud']);

        $this->putJson($this->api("skus/{$id}"), ['sku' => 'ET-WTS001-316', 'product_name' => 'SS 316 Stud (renamed)', 'size' => '25MM'])->assertOk()
            ->assertJsonPath('data.size', '25MM');
        $this->patchJson($this->api("skus/{$id}/status"), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame([], array_column($this->getJson($this->api('skus/options?search=ET-WTS001'))->json('data'), 'sku')); // not offered for new entries
        $this->get($this->api('skus?export=1'))->assertOk();
    }

    public function test_sku_csv_import_validates_rows_before_creating(): void
    {
        $this->sku('ET-EXISTS');
        $this->actingAs($this->admin);
        $file = "SKU,Product Name,Category,Material,Size,Reorder Level\nET-NEW1,Stud A,Stud,316,35MM,10\nET-EXISTS,Old,Stud,304,,\n,Missing code,,,,\nET-DUP,One,,,,\nET-DUP,Two,,,,\nET-NEW2,Stud B,Stud,304,,abc\n";

        $preview = $this->post($this->api('skus/import'), ['file' => $this->csv($file), 'dry_run' => 1], ['Accept' => 'application/json'])->assertOk();
        $preview->assertJsonPath('summary', ['total' => 6, 'ready' => 1, 'created' => 0, 'skipped' => 1, 'failed' => 4]);
        $this->assertSame(1, Sku::count()); // dry run writes nothing

        $this->post($this->api('skus/import'), ['file' => $this->csv($file)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('summary.created', 1);
        $this->assertSame('Stud A', Sku::where('sku', 'ET-NEW1')->sole()->product_name);
        $this->post($this->api('skus/import'), ['file' => $this->csv("Name\nX\n")], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_vendor_registration_is_unique_and_editable(): void
    {
        $this->actingAs($this->admin);
        $vendor = $this->postJson($this->api('vendors'), ['name' => 'Jay Khodiyar Industries', 'contact_person' => 'Ravi Vora', 'phone' => '7046575689', 'city' => 'Rajkot'])
            ->assertCreated()->json('data');
        $this->assertSame('VEN-0001', $vendor['code']);
        $this->postJson($this->api('vendors'), ['name' => 'jay khodiyar industries'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->api('vendors'), ['name' => 'Bad', 'phone' => 'abc', 'email' => 'nope'])->assertJsonValidationErrors(['phone', 'email']);

        $this->putJson($this->api("vendors/{$vendor['id']}"), ['name' => 'Jay Khodiyar Industries', 'city' => 'Ahmedabad', 'notes' => 'Roundbar supplier'])
            ->assertOk()->assertJsonPath('data.city', 'Ahmedabad');
        $this->assertSame(['Jay Khodiyar Industries'], array_column($this->getJson($this->api('vendors?search=ahmed'))->json('data'), 'name'));
        $this->patchJson($this->api("vendors/{$vendor['id']}/status"), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertCount(0, $this->getJson($this->api('vendors/options'))->json('data'));
    }

    public function test_vendor_sku_mapping_is_many_to_many_without_duplicates(): void
    {
        $a = $this->sku('ET-A');
        $b = $this->sku('ET-B');
        $inactive = $this->sku('ET-OLD', ['is_active' => false]);
        $birju = $this->vendor('Birju Buff');
        $verti = $this->vendor('Vertitech');
        $this->actingAs($this->admin);

        $this->postJson($this->api('vendor-skus'), ['vendor_id' => $birju->id, 'sku_ids' => [$a->id, $b->id]])->assertCreated()
            ->assertJsonPath('data.0.sku.product_name', 'Product ET-A'); // product name always from SKU Master
        $this->postJson($this->api('vendor-skus'), ['vendor_id' => $verti->id, 'sku_ids' => [$a->id]])->assertCreated();
        $this->postJson($this->api('vendor-skus'), ['vendor_id' => $birju->id, 'sku_ids' => [$a->id]])->assertUnprocessable()
            ->assertJsonPath('errors', ['sku_ids.0' => ['Birju Buff is already mapped to ET-A.']]);
        $this->postJson($this->api('vendor-skus'), ['vendor_id' => $birju->id, 'sku_ids' => [$inactive->id]])->assertJsonValidationErrors('sku_ids.0');

        $this->assertCount(2, $this->getJson($this->api("vendor-skus?vendor_id={$birju->id}"))->json('data'));
        $this->assertSame(['Birju Buff', 'Vertitech'], array_column($this->getJson($this->api("vendors?sku_id={$a->id}"))->json('data'), 'name'));
        $this->getJson($this->api("vendors/{$birju->id}"))->assertOk()->assertJsonCount(2, 'data.mappings');
        $options = collect($this->getJson($this->api("skus/options?vendor_id={$verti->id}"))->json('data'))->keyBy('sku');
        $this->assertTrue($options['ET-A']['mapped']);
        $this->assertFalse($options['ET-B']['mapped']);

        $mapping = VendorSku::where('vendor_id', $verti->id)->sole();
        $this->putJson($this->api("vendor-skus/{$mapping->id}"), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson($this->api("vendor-skus/{$mapping->id}"))->assertOk();
        $this->assertSame(2, VendorSku::count());

        $verti->update(['is_active' => false]);
        $this->postJson($this->api('vendor-skus'), ['vendor_id' => $verti->id, 'sku_ids' => [$b->id]])->assertJsonValidationErrors('vendor_id');
    }

    public function test_vendor_and_mapping_imports_never_invent_skus(): void
    {
        $this->sku('ET-A');
        $this->sku('ET-OFF', ['is_active' => false]);
        $this->vendor('Birju Buff');
        $this->actingAs($this->admin);

        $vendors = "Vendor Name,Contact Person,City,Notes\nAbhay Steel,Mukeshbhai,Rajkot,Tiles\nBirju Buff,Brijesh,,\n,No name,,\n";
        $this->post($this->api('vendors/import'), ['file' => $this->csv($vendors)], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('summary', ['total' => 3, 'ready' => 1, 'created' => 1, 'skipped' => 1, 'failed' => 1]);
        $this->assertSame('Mukeshbhai', Vendor::where('name', 'Abhay Steel')->sole()->contact_person);

        $mappings = "Vendor Name,SKU Supplied\nBirju Buff,ET-A\nBirju Buff,ET-UNKNOWN\nNobody,ET-A\nAbhay Steel,ET-OFF\nAbhay Steel,ET-A\nAbhay Steel,et-a\n";
        $result = $this->post($this->api('vendor-skus/import'), ['file' => $this->csv($mappings)], ['Accept' => 'application/json'])->assertOk();
        $result->assertJsonPath('summary.created', 2);
        $messages = array_column($result->json('rows'), 'message');
        $this->assertContains('SKU ET-UNKNOWN does not exist in SKU Master.', $messages);
        $this->assertContains('Vendor Nobody is not registered in Vendor Master.', $messages);
        $this->assertSame(2, Sku::count()); // nothing created from mapping rows
    }

    public function test_master_permissions_are_enforced(): void
    {
        $sku = $this->sku();
        $vendor = $this->vendor('V');
        $viewer = $this->staff(['skus.view', 'vendors.view', 'vendor-skus.view']);
        $this->actingAs($viewer);

        $this->getJson($this->api('skus'))->assertOk();
        $this->putJson($this->api("skus/{$sku->id}"), ['sku' => 'X', 'product_name' => 'Y'])->assertForbidden();
        $this->patchJson($this->api("skus/{$sku->id}/status"), ['is_active' => false])->assertForbidden();
        $this->postJson($this->api('vendors'), ['name' => 'X'])->assertForbidden();
        $this->postJson($this->api('vendor-skus'), ['vendor_id' => $vendor->id, 'sku_ids' => [$sku->id]])->assertForbidden();
        $this->get($this->api('vendors?export=1'))->assertForbidden();
        $this->post($this->api('skus/import'), [], ['Accept' => 'application/json'])->assertForbidden();
    }
}
