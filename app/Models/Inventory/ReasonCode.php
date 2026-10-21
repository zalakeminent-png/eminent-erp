<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;

class ReasonCode extends Model
{
    use BelongsToCompany, TracksAuthors;

    protected $table = 'inventory_reason_codes';

    protected $fillable = ['code', 'name', 'category', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
