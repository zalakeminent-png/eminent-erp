<?php

namespace App\Models\Stock;

use Illuminate\Database\Eloquent\Model;

/** Cached live balance per SKU + storage location; always equals the ledger sum. */
class StockLevel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
