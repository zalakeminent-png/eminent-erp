<?php

namespace App\Services\MasterData;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Gap-tolerant running numbers per company and key (row-locked), used for
 * generated SKUs and BOM / routing / QC specification numbers.
 */
class NumberSequence
{
    public function next(string $key): int
    {
        $companyId = Company::current()?->id;

        return DB::transaction(function () use ($key, $companyId) {
            $row = DB::table('number_sequences')->where('company_id', $companyId)->where('key', $key)->lockForUpdate()->first();

            if (! $row) {
                DB::table('number_sequences')->insert([
                    'company_id' => $companyId, 'key' => $key, 'last_number' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 1;
            }

            $next = $row->last_number + 1;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    public function formatted(string $key, string $prefix, int $padding = 5): string
    {
        return $prefix.str_pad((string) $this->next($key), $padding, '0', STR_PAD_LEFT);
    }
}
