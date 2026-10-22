<?php

namespace Tests\Feature\Stock;

use App\Models\Factory;
use App\Models\Stock\Sku;
use App\Models\Stock\StockDocument;
use App\Models\Stock\Vendor;
use App\Models\Stock\VendorSku;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Tests\TestCase;

abstract class StockTestCase extends TestCase
{
    protected User $admin;

    protected Factory $factory;

    protected WarehouseLocation $rack;

    protected WarehouseLocation $shelf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->factory = Factory::factory()->create(['code' => 'F1', 'name' => 'Main Plant']);
        $store = Warehouse::create(['factory_id' => $this->factory->id, 'code' => 'MAIN', 'name' => 'Main Storage', 'is_active' => true]);
        $this->rack = WarehouseLocation::create(['warehouse_id' => $store->id, 'code' => 'RACK-A', 'name' => 'Rack A', 'type' => 'rack', 'is_active' => true]);
        $this->shelf = WarehouseLocation::create(['warehouse_id' => $store->id, 'parent_id' => $this->rack->id, 'code' => 'A1', 'name' => 'Shelf A1', 'type' => 'shelf', 'is_active' => true]);
    }

    protected function sku(string $code = 'ET-WTS001-316', array $extra = []): Sku
    {
        return Sku::create(array_merge(['sku' => $code, 'product_name' => "Product {$code}", 'category' => 'Stud', 'material' => '316', 'is_active' => true], $extra));
    }

    protected function vendor(string $name = 'Birju Buff', array $skus = []): Vendor
    {
        static $n = 0;
        $vendor = new Vendor(['name' => $name, 'is_active' => true]);
        $vendor->code = 'VEN-T'.(++$n);
        $vendor->save();
        foreach ($skus as $sku) {
            VendorSku::create(['vendor_id' => $vendor->id, 'sku_id' => $sku->id, 'is_active' => true]);
        }

        return $vendor;
    }

    /** A user restricted to one factory with exactly these permissions. */
    protected function staff(array $permissions, ?Factory $factory = null): User
    {
        $user = $this->createUserWithPermissions($permissions);
        $user->forceFill(['factory_id' => ($factory ?? $this->factory)->id])->save();

        return $user->fresh();
    }

    protected function base(array $extra = []): array
    {
        return array_merge(['document_date' => now()->toDateString(), 'factory_id' => $this->factory->id, 'location_id' => $this->rack->id], $extra);
    }

    /** Creates and posts a document through the API as the admin. */
    protected function posted(string $endpoint, array $payload): array
    {
        $this->actingAs($this->admin);
        $id = $this->postJson($this->api($endpoint), $payload)->assertCreated()->json('data.id');

        return $this->postJson($this->api("{$endpoint}/{$id}/post"))->assertOk()->json('data');
    }

    protected function opening(Sku $sku, string $qty, ?WarehouseLocation $location = null): array
    {
        return $this->posted('opening-stock', $this->base(['sku_id' => $sku->id, 'quantity' => $qty, 'location_id' => ($location ?? $this->rack)->id]));
    }

    protected function overviewRow(Sku $sku, array $filters = []): array
    {
        $this->actingAs($this->admin);
        $rows = collect($this->getJson($this->api('stock/overview?'.http_build_query($filters + ['per_page' => 200])))->assertOk()->json('data'));

        return $rows->firstWhere('sku', $sku->sku);
    }

    protected function documentCount(): int
    {
        return StockDocument::count();
    }
}
