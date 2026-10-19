<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use TracksAuthors;

    protected $fillable = ['factory_id', 'type_id', 'code', 'name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(StructureType::class, 'type_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** Users whose primary department this is. */
    public function primaryUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Users assigned to this department as an additional department. */
    public function additionalUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('assigned_by')->withTimestamps();
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /** All members: primary or additional assignment. */
    public function members(): Builder
    {
        return User::query()->where(fn ($q) => $q
            ->where('department_id', $this->id)
            ->orWhereHas('additionalDepartments', fn ($d) => $d->whereKey($this->id)));
    }
}
