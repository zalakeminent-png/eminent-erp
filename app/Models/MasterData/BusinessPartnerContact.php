<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

class BusinessPartnerContact extends Model
{
    protected $fillable = ['name', 'designation', 'email', 'phone', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}
