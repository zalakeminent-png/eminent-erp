<?php

namespace Tests\Feature\Inventory;

use App\Models\Factory;
use App\Models\Inventory\Balance;
use App\Models\Inventory\InventoryTransaction;
use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\ReasonCode;
use App\Models\MasterData\BusinessPartner;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\Movement;
use App\Services\Inventory\PostingContext;
use App\Services\Inventory\StockPostingService;
use App\Services\MasterData\ItemService;
use App\Support\Decimal;
use Tests\TestCase;

abstract class InventoryTestCase extends TestCase
{
    protected User $admin;

    protected Factory $factory;

    protected Warehouse $store;

    protected WarehouseLocation $rack;

    protected WarehouseLocation $rack2;

    protected WarehouseLocation $wip;

    protected WarehouseLocation $qcArea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdmin();
        $this->factory = Factory::factory()->create(['code' => 'F1']);
        $this->store = Warehouse::create(['factory_id' => $this->factory->id, 'code' => 'RMS', 'name' => 'Raw Material Store', 'stock_purpose' => 'raw_material', 'is_active' => true]);
        $this->rack = $this->location($this->store, 'R1');
        $this->rack2 = $this->location($this->store, 'R2');
        $this->qcArea = $this->location($this->store, 'QC', ['stock_purpose' => 'qc_pending']);
        $shop = Warehouse::create(['factory_id' => $this->factory->id, 'code' => 'SHOP', 'name' => 'Machine Shop', 'stock_purpose' => 'production', 'is_active' => true]);
        $this->wip = $this->location($shop, 'WIP1');
    }

    protected function location(Warehouse $warehouse, string $code, array $extra = []): WarehouseLocation
    {
        return WarehouseLocation::create(array_merge([
            'warehouse_id' => $warehouse->id, 'code' => $code, 'name' => "Location {$code}", 'type' => 'rack', 'is_active' => true,
        ], $extra));
    }

    /** A user assigned to the test factory with exactly these permissions. */
    protected function staff(array $permissions): User
    {
        $user = $this->createUserWithPermissions($permissions);
        $user->forceFill(['factory_id' => $this->factory->id])->save();

        return $user->fresh();
    }

    protected function uom(string $code): UnitOfMeasure
    {
        return UnitOfMeasure::where('code', $code)->sole();
    }

    protected function item(string $code, string $uom = 'KG', array $extra = []): Item
    {
        $this->actingAs($this->admin);

        return app(ItemService::class)->save(null, array_merge([
            'code' => $code,
            'name' => "Item {$code}",
            'category_id' => ItemCategory::where('code', 'RM')->sole()->id,
            'base_uom_id' => $this->uom($uom)->id,
            'is_active' => true,
        ], $extra));
    }

    protected function supplier(): BusinessPartner
    {
        return BusinessPartner::create(['code' => 'SUP-'.uniqid(), 'name' => 'Steel Supplier', 'is_supplier' => true, 'is_active' => true]);
    }

    protected function reason(string $code = 'DAMAGED'): ReasonCode
    {
        return ReasonCode::where('code', $code)->sole();
    }

    /** Posts stock directly through the engine (test setup only). */
    protected function receive(Item $item, WarehouseLocation $location, string $qty, ?string $cost = null, string $status = 'available'): InventoryTransaction
    {
        return app(StockPostingService::class)->post('test_receipt', [
            new Movement($item->id, $location->id, $status, $qty, $cost),
        ], new PostingContext('test:'.uniqid('', true), $this->admin->id));
    }

    protected function onHand(Item $item, WarehouseLocation $location, string $status = 'available'): string
    {
        $balance = Balance::query()->where(['item_id' => $item->id, 'location_id' => $location->id, 'stock_status' => $status])->first();

        return Decimal::normalize((string) ($balance?->quantity ?? '0'));
    }

    /** Every cached balance must equal the sum of its ledger entries. */
    protected function assertBalancesMatchLedger(): void
    {
        foreach (Balance::all() as $balance) {
            $sum = LedgerEntry::query()->where(['item_id' => $balance->item_id, 'location_id' => $balance->location_id, 'stock_status' => $balance->stock_status])
                ->pluck('quantity')->reduce(fn ($c, $q) => bcadd($c, (string) $q, 6), '0');
            $this->assertSame(Decimal::normalize($sum), Decimal::normalize((string) $balance->quantity), "Balance {$balance->id} drifted from the ledger.");
        }
    }
}
