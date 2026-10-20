<?php

namespace App\Services\MasterData;

use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\MaterialGrade;
use Illuminate\Support\Str;

/**
 * Builds item codes from the configurable pattern, e.g.
 * "{category}-{grade}-{seq}" → RM-SS316-001, PK-001 (no grade).
 * Generated codes are suggestions in the same namespace as manual codes;
 * any other format can be entered manually.
 */
class SkuGenerator
{
    public function __construct(private readonly NumberSequence $sequence) {}

    public function mode(): string
    {
        return in_array(config('erp.master_data.sku.mode'), ['manual', 'auto', 'optional'], true)
            ? config('erp.master_data.sku.mode')
            : 'optional';
    }

    public function generate(ItemCategory $category, ?MaterialGrade $grade = null): string
    {
        $pattern = (string) config('erp.master_data.sku.pattern', '{category}-{grade}-{seq}');
        $padding = max(1, (int) config('erp.master_data.sku.sequence_padding', 3));

        $tokens = [
            '{category}' => $this->clean($category->resolvedSkuPrefix() ?? $category->code),
            '{grade}' => $grade ? $this->clean($grade->code) : '',
        ];
        $prefix = strtr(str_replace('{seq}', '#SEQ#', $pattern), $tokens);
        // Drop separators left behind by empty tokens ("RM--#SEQ#" → "RM-#SEQ#").
        $prefix = trim(preg_replace('/([-_\/.])\1+/', '$1', $prefix), '-_/.');
        $key = 'sku:'.Str::before($prefix, '#SEQ#');

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $code = str_replace('#SEQ#', str_pad((string) $this->sequence->next($key), $padding, '0', STR_PAD_LEFT), $prefix);
            if (! Item::query()->forCurrentCompany()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique item code.');
    }

    private function clean(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $value));
    }
}
