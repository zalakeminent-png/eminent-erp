<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Node in a warehouse's storage hierarchy. Holds configuration only — stock
 * quantities belong to the future Inventory module.
 */
class WarehouseLocation extends Model
{
    use TracksAuthors;

    protected $fillable = [
        'warehouse_id', 'parent_id', 'code', 'name', 'type', 'description',
        'is_virtual', 'is_pickable', 'is_receivable', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_virtual' => 'boolean',
            'is_pickable' => 'boolean',
            'is_receivable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Locations that may be chosen for new operational transactions: the
     * location, its warehouse and its factory must all be active. Future
     * modules should select locations through this scope.
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('warehouse_locations.is_active', true)
            ->whereHas('warehouse', fn ($w) => $w->where('is_active', true)
                ->whereHas('factory', fn ($f) => $f->where('is_active', true)));
    }
}
