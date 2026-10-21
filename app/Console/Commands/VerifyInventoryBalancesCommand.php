<?php

namespace App\Console\Commands;

use App\Models\Inventory\Balance;
use App\Support\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only integrity check: every cached balance must equal the sum of its
 * ledger entries, and every ledger identity must have a balance row. It
 * reports differences; it never changes stock.
 */
class VerifyInventoryBalancesCommand extends Command
{
    protected $signature = 'inventory:verify-balances';

    protected $description = 'Check that cached stock balances match the immutable stock ledger (read-only)';

    public function handle(): int
    {
        $ledger = DB::table('inventory_ledger_entries')
            ->selectRaw('item_id, location_id, stock_status, SUM(quantity) as total')
            ->groupBy('item_id', 'location_id', 'stock_status')->get()
            ->keyBy(fn ($r) => "{$r->item_id}|{$r->location_id}|{$r->stock_status}");

        $problems = [];
        foreach (Balance::query()->cursor() as $balance) {
            $key = "{$balance->item_id}|{$balance->location_id}|{$balance->stock_status}";
            $expected = Decimal::normalize((string) ($ledger->get($key)?->total ?? '0'));
            $actual = Decimal::normalize((string) $balance->quantity);
            if (bccomp($expected, $actual, 6) !== 0) {
                $problems[] = [$key, $actual, $expected];
            }
            $ledger->forget($key);
        }
        foreach ($ledger as $key => $row) {
            if (bccomp((string) $row->total, '0', 6) !== 0) {
                $problems[] = [$key, 'missing', Decimal::normalize((string) $row->total)];
            }
        }

        if ($problems) {
            $this->error(count($problems).' balance(s) differ from the ledger:');
            $this->table(['item|location|status', 'cached balance', 'ledger total'], $problems);

            return self::FAILURE;
        }

        $this->info('All cached balances match the stock ledger.');

        return self::SUCCESS;
    }
}
