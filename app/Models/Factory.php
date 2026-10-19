<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthors;
use Database\Factories\FactoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    /** @use HasFactory<FactoryFactory> */
    use HasFactory, TracksAuthors;

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'address_line_1', 'address_line_2', 'city', 'state',
        'postal_code', 'country', 'contact_person', 'contact_number', 'email', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
