<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'legal_name', 'code', 'gstin', 'pan', 'cin', 'email', 'phone', 'website',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'currency', 'timezone', 'financial_year_start_month', 'financial_year_end_month',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'financial_year_start_month' => 'integer',
            'financial_year_end_month' => 'integer',
        ];
    }

    /**
     * The company the application currently operates under. Single-company
     * for now; swap for a tenant resolver when multi-company support arrives.
     */
    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->orderBy('id')->first()
            ?? static::query()->orderBy('id')->first();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? Storage::disk(config('erp.company.logo_disk'))->url($this->logo_path)
            : null;
    }
}
