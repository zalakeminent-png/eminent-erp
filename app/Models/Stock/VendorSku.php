<?php

namespace App\Models\Stock;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which SKUs a vendor supplies (many-to-many with status). */
class VendorSku extends Model
{
    use TracksAuthors;

    protected $table = 'vendor_skus';

    protected $fillable = ['vendor_id', 'sku_id', 'is_active', 'remarks'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
