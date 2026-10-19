<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Business safeguards that sit on top of plain permission checks:
 * privilege-escalation prevention and last-administrator protection.
 */
class AccessGuard
{
    /**
     * An actor may only hand out permissions they hold themselves, so a
     * user with users.update cannot promote anyone (or themselves) above
     * their own level.
     *
     * @param  iterable<string>  $permissionNames
     */
    public function ensureActorHoldsPermissions(User $actor, iterable $permissionNames, string $field = 'permissions'): void
    {
        $missing = collect($permissionNames)->diff($actor->permissionNames())->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                $field => 'You cannot grant permissions you do not hold yourself: '.$missing->take(5)->implode(', ')
                    .($missing->count() > 5 ? ' …' : ''),
            ]);
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    public function ensureCanAssignRoles(User $actor, Collection $roles): void
    {
        $roles->loadMissing('permissions');

        $this->ensureActorHoldsPermissions(
            $actor,
            $roles->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique(),
            'role_ids',
        );
    }

    /**
     * An actor may only manage (edit, (de)activate, reset) users whose
     * permissions are a subset of their own. Otherwise a holder of
     * users.update could, for example, change an administrator's email and
     * take over the account via password reset.
     */
    public function ensureCanManage(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            return;
        }

        $missing = collect($target->permissionNames())->diff($actor->permissionNames());

        if ($missing->isNotEmpty()) {
            throw new AuthorizationException('You cannot manage a user who has permissions you do not hold.');
        }
    }

    /**
     * Users may not change the role assignments or status of their own account.
     */
    public function ensureNotSelf(User $actor, User $target, string $message): void
    {
        if ($actor->is($target)) {
            throw new AuthorizationException($message);
        }
    }

    /**
     * Users may not edit the permissions of a role they currently hold.
     */
    public function ensureNotOwnRole(User $actor, Role $role): void
    {
        if ($actor->roles->contains($role)) {
            throw new AuthorizationException('You cannot change the permissions of a role assigned to you.');
        }
    }

    /**
     * Prevents a change that would leave the system without an active
     * holder of the primary administrator role.
     *
     * @param  Collection<int, Role>|null  $newRoles  null when roles are unchanged
     */
    public function ensureAdministratorRemains(User $target, ?bool $willBeActive = null, ?Collection $newRoles = null): void
    {
        $target->loadMissing('roles');

        if (! $target->is_active || ! $target->isPrimaryAdmin()) {
            return;
        }

        $staysActive = $willBeActive ?? true;
        $keepsAdminRole = $newRoles === null
            || $newRoles->contains(fn (Role $role) => $role->isPrimaryAdmin());

        if ($staysActive && $keepsAdminRole) {
            return;
        }

        if ($this->activeAdministratorCount() <= 1) {
            throw ValidationException::withMessages([
                $staysActive ? 'role_ids' : 'is_active' => 'This is the last active administrator. Assign the administrator role to another active user first.',
            ]);
        }
    }

    public function activeAdministratorCount(): int
    {
        $role = Role::primaryAdmin();

        return $role ? $role->users()->where('is_active', true)->count() : 0;
    }
}
