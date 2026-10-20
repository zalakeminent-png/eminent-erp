<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer and/or supplier. Commercial documents belong to later modules. */
class BusinessPartner extends Model
{
    use BelongsToCompany, TracksAuthors;

    protected $fillable = [
        'code', 'name', 'is_customer', 'is_supplier', 'contact_person', 'email', 'phone', 'website', 'gstin', 'pan',
        'currency', 'payment_terms', 'remarks', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(BusinessPartnerContact::class, 'partner_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(BusinessPartnerAddress::class, 'partner_id');
    }

    public function partyType(): string
    {
        return match (true) {
            $this->is_customer && $this->is_supplier => 'both',
            $this->is_customer => 'customer',
            $this->is_supplier => 'supplier',
            default => 'none',
        };
    }
}
