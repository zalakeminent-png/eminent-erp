<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\LedgerEntry;
use App\Models\Inventory\OpeningStockImport;
use App\Models\Inventory\Reservation;
use App\Services\Inventory\Movement;
use App\Services\Inventory\OpeningStockService;
use App\Services\Inventory\PostingContext;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockCountService;
use App\Services\Inventory\StockPostingService;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StockServicesTest extends InventoryTestCase
{
    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(',', $r), $rows);

        return UploadedFile::fake()->createWithContent('opening.csv', implode("\n", $lines)."\n");
    }

    public function test_reservations_respect_available_to_promise_and_are_idempotent(): void
    {
        $item = $this->item('RM-RSV');
        $this->receive($item, $this->rack, '10');
        $service = app(ReservationService::class);
        $data = ['item_id' => $item->id, 'warehouse_id' => $this->store->id, 'quantity' => '6', 'uom_id' => $item->base_uom_id, 'idempotency_key' => 'rsv-key-0001'];

        $first = $service->reserve($data, $this->admin);
        $again = $service->reserve($data, $this->admin);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Reservation::count());

        try {
            $service->reserve([...$data, 'quantity' => '5', 'idempotency_key' => 'rsv-key-0002'], $this->admin);
            $this->fail('Over-reservation accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only 4', $e->getMessage());
        }

        $partly = $service->release($first, '2', 'Order reduced', $this->admin);
        $this->assertSame(Reservation::PARTIALLY_RELEASED, $partly->status);
        $service->reserve([...$data, 'quantity' => '6', 'idempotency_key' => 'rsv-key-0003'], $this->admin);

        $released = $service->release($partly, null, null, $this->admin);
        $this->assertSame(Reservation::RELEASED, $released->status);
        $this->assertSame(3, $released->events()->count()); // created + partial release + final release
    }

    public function test_expired_reservations_stop_holding_stock(): void
    {
        $item = $this->item('RM-EXP');
        $this->receive($item, $this->rack, '5');
        $service = app(ReservationService::class);
        $reservation = $service->reserve(['item_id' => $item->id, 'warehouse_id' => $this->store->id, 'quantity' => '5', 'uom_id' => $item->base_uom_id, 'expires_on' => now()->toDateString()], $this->admin);

        $this->travel(2)->days();
        $this->assertSame(1, $service->expire());
        $this->assertSame(Reservation::EXPIRED, $reservation->fresh()->status);
        app(StockPostingService::class)->post('test', [new Movement($item->id, $this->rack->id, 'available', '-5')], new PostingContext('exp-1', $this->admin->id));
        $this->assertSame('0', $this->onHand($item, $this->rack));
    }

    public function test_stock_count_snapshot_lock_and_variance_posting(): void
    {
        $item = $this->item('RM-CNT');
        $found = $this->item('RM-FOUND');
        $this->receive($item, $this->rack, '20', '10');
        $counter = $this->staff(['inventory.counts.create', 'inventory.counts.submit']);
        $manager = $this->staff(['inventory.counts.approve', 'inventory.counts.post']);
        $service = app(StockCountService::class);

        $session = $service->create(['warehouse_id' => $this->store->id, 'location_ids' => [$this->rack->id]], $counter);
        $session = $service->start($session, $counter);
        $line = $session->lines()->sole();
        $this->assertSame('20', Decimal::normalize((string) $line->system_quantity));

        // Locked: no other posting into the counted location.
        try {
            $this->receive($item, $this->rack, '1');
            $this->fail('Posting into a counted location accepted.');
        } catch (ValidationException) {
        }

        $service->record($session, [
            ['line_id' => $line->id, 'counted_quantity' => '18'],
            ['item_id' => $found->id, 'location_id' => $this->rack->id, 'counted_quantity' => '3'],
        ], $counter);

        $session = $service->submit($session, $counter);
        try {
            $service->approve($session, $counter);
            $this->fail('Counter approved own count.');
        } catch (AuthorizationException) {
        }
        $session = $service->post($service->approve($session, $manager), $manager);

        $this->assertSame('posted', $session->status);
        $this->assertSame('18', $this->onHand($item, $this->rack));
        $this->assertSame('3', $this->onHand($found, $this->rack));
        $this->assertSame(2, LedgerEntry::where('counterparty', 'count')->count());
        $this->receive($item, $this->rack, '1'); // lock released
        $this->assertBalancesMatchLedger();
    }

    public function test_opening_stock_import_validates_rows_and_posts_atomically(): void
    {
        Storage::fake('local');
        $bar = $this->item('RM-OPN1');
        $plate = $this->item('RM-OPN2');
        $service = app(OpeningStockService::class);

        $import = $service->upload($this->csv([
            ['Item Code', 'Warehouse Code', 'Location Code', 'Quantity', 'UOM', 'Stock Status', 'Unit Cost'],
            ['RM-OPN1', 'RMS', 'R1', '100', 'KG', '', '55.5'],
            ['rm-opn2', 'rms', 'r2', '40.25', '', 'qc_pending', ''],
            ['RM-NOPE', 'RMS', 'R1', '5', '', '', ''],          // unknown item
            ['RM-OPN1', 'RMS', 'R9', '5', '', '', ''],          // unknown location
            ['RM-OPN2', 'RMS', 'R1', '-3', '', '', ''],         // negative
            ['RM-OPN2', 'RMS', 'R1', '2', '', 'in_transit', ''], // not allowed
        ]), $this->admin);
        $this->assertSame('Item Code', $import->mapping['item_code']);
        $this->assertSame(6, $import->total_rows);

        $import = $service->validate($import, $import->mapping, $this->admin);
        $this->assertSame(2, $import->accepted_rows);
        $this->assertSame(4, $import->rejected_rows);
        $this->assertSame(0, LedgerEntry::count()); // validation never posts

        $import = $service->submit($import, $this->admin);
        $import = $service->approve($import, $this->admin);
        $import = $service->post($import, $this->admin);

        $this->assertSame(OpeningStockImport::POSTED, $import->status);
        $this->assertSame('100', $this->onHand($bar, $this->rack));
        $this->assertSame('40.25', $this->onHand($plate, $this->rack2, 'qc_pending'));
        $this->assertSame(2, LedgerEntry::where('counterparty', 'opening')->count());

        // Loading the same opening stock again is refused.
        $second = $service->upload($this->csv([['Item Code', 'Warehouse Code', 'Location Code', 'Quantity'], ['RM-OPN1', 'RMS', 'R1', '100']]), $this->admin);
        $second = $service->validate($second, $second->mapping, $this->admin);
        $this->assertSame(0, $second->accepted_rows);
        $this->assertStringContainsString('already has stock transactions', $second->rows()->sole()->errors[0]);

        // Reversal is compensating, not a deletion.
        $reversed = $service->reverse($import, 'Wrong file loaded', $this->admin);
        $this->assertSame(OpeningStockImport::REVERSED, $reversed->status);
        $this->assertSame('0', $this->onHand($bar, $this->rack));
        $this->assertSame(4, LedgerEntry::count());
    }

    public function test_opening_stock_rejects_duplicate_rows_in_one_file(): void
    {
        Storage::fake('local');
        $this->item('RM-DUP');
        $service = app(OpeningStockService::class);
        $import = $service->upload($this->csv([
            ['Item Code', 'Warehouse Code', 'Location Code', 'Quantity'],
            ['RM-DUP', 'RMS', 'R1', '1'],
            ['RM-DUP', 'RMS', 'R1', '2'],
        ]), $this->admin);
        $import = $service->validate($import, $import->mapping, $this->admin);

        $this->assertSame(0, $import->accepted_rows);
        $this->expectException(ValidationException::class);
        $service->submit($import, $this->admin);
    }
}
