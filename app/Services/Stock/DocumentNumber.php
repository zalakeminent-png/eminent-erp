<?php

namespace App\Services\Stock;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Gap-free document numbers, safe under concurrent requests (row lock). */
class DocumentNumber
{
    public function next(string $key, string $prefix, int $padding = 6): string
    {
        $companyId = Company::current()?->id;

        return DB::transaction(function () use ($companyId, $key, $prefix, $padding) {
            DB::table('number_sequences')->insertOrIgnore([
                'company_id' => $companyId, 'key' => $key, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('number_sequences')->where('company_id', $companyId)->where('key', $key)->lockForUpdate()->first();
            $next = (int) $row->last_number + 1;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

            return $prefix.str_pad((string) $next, $padding, '0', STR_PAD_LEFT);
        });
    }
}
