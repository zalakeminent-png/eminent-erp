<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'description', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Spatie resolves the user model from the current default guard, which
     * is "sanctum" (no provider) inside API requests; bind it explicitly.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key'),
        );
    }

    public static function primaryAdmin(): ?self
    {
        return static::query()
            ->where('name', config('erp.primary_admin_role'))
            ->where('guard_name', 'web')
            ->first();
    }

    public function isPrimaryAdmin(): bool
    {
        return $this->name === config('erp.primary_admin_role') && $this->guard_name === 'web';
    }
}
