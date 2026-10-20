<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

class BusinessPartnerAddress extends Model
{
    public const TYPES = ['billing', 'shipping'];

    protected $fillable = ['address_type', 'label', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
