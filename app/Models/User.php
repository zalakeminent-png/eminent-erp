<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\UserInvitationNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    // factory_id / department_id are set only through the assignment
    // workflow (StructureAssignmentService), never by mass assignment.
    protected $fillable = [
        'company_id', 'employee_code', 'first_name', 'last_name', 'email', 'mobile',
        'password', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Primary factory assignment ("where" the user works, not "what" they may do). */
    public function primaryFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    public function primaryDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function additionalDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withPivot('assigned_by')->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Permissions granted through a role only count while the role is active.
     */
    protected function hasPermissionViaRole(PermissionContract $permission): bool
    {
        $activeRoleIds = $this->roles->where('is_active', true)->modelKeys();

        return $permission->roles->pluck('id')->intersect($activeRoleIds)->isNotEmpty();
    }

    public function getPermissionsViaRoles(): Collection
    {
        return $this->loadMissing('roles', 'roles.permissions')
            ->roles->where('is_active', true)
            ->flatMap(fn ($role) => $role->permissions)
            ->sort()->values();
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return $this->getAllPermissions()->pluck('name')->unique()->sort()->values()->all();
    }

    public function isPrimaryAdmin(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->isPrimaryAdmin());
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendInvitationNotification(string $token): void
    {
        $this->notify(new UserInvitationNotification($token));
    }
}
