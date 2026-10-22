<?php

namespace App\Models\Stock;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SKU Master: a stocked product. Deactivated SKUs keep their history. */
class Sku extends Model
{
    use BelongsToCompany, TracksAuthors;

    protected $fillable = ['sku', 'product_name', 'category', 'material', 'size', 'unit', 'reorder_level', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'reorder_level' => 'decimal:3'];
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(VendorSku::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StockDocument::class);
    }
}
