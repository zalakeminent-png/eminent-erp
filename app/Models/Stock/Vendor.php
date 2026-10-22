<?php

namespace App\Models\Stock;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vendor Master: registered once; supplies SKUs through vendor_skus. */
class Vendor extends Model
{
    use BelongsToCompany, TracksAuthors;

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'city', 'address', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
