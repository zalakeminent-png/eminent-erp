<?php

namespace App\Services\Structure;

use App\Models\Department;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where a user works: primary factory, primary department and additional
 * departments. Kept separate from role assignment (what a user may do).
 */
class StructureAssignmentService
{
    public function __construct(private readonly StructureScope $scope) {}

    /**
     * @param  list<int>  $additionalIds
     * @return array<string, mixed> change summary for the audit log
     */
    public function assignUser(User $actor, User $target, ?int $factoryId, ?int $primaryDepartmentId, array $additionalIds): array
    {
        $this->ensureMayChange($actor, $target);
        $target->loadMissing('additionalDepartments');

        $primary = $primaryDepartmentId ? Department::query()->findOrFail($primaryDepartmentId) : null;
        if ($primary) {
            $factoryId ??= $primary->factory_id;
            if ($primary->factory_id !== $factoryId) {
                $this->fail('primary_department_id', 'The primary department must belong to the selected factory.');
            }
        }

        // Current assignments in factories the actor cannot see are preserved.
        $current = $target->additionalDepartments;
        $hidden = $current->reject(fn (Department $d) => $this->scope->canAccessFactory($actor, $d->factory_id));
        if (! $this->scope->hasGlobalAccess($actor) && $target->factory_id && $target->factory_id !== $factoryId
            && ! $this->scope->canAccessFactory($actor, $target->factory_id)) {
            throw new AuthorizationException('This user is assigned to a factory outside your access scope.');
        }

        $requested = Department::query()->whereKey(array_diff($additionalIds, [$primaryDepartmentId]))->get();

        // Newly selected factory/departments must be active and in the actor's scope.
        if ($factoryId && $factoryId !== $target->factory_id) {
            $factory = Factory::query()->find($factoryId);
            if (! $factory?->is_active || ! $this->scope->canAccessFactory($actor, $factoryId)) {
                $this->fail('factory_id', 'Select an active factory you have access to.');
            }
        }
        foreach ($requested->concat(array_filter([$primary])) as $department) {
            $isNew = $department->id !== $target->department_id && ! $current->contains($department);
            if ($isNew && (! $department->is_active || ! $this->scope->canAccessFactory($actor, $department->factory_id))) {
                $field = $department->is($primary) ? 'primary_department_id' : 'department_ids';
                $this->fail($field, "Department {$department->name} is inactive or outside your access scope.");
            }
        }

        $finalAdditional = $requested->pluck('id')->merge($hidden->pluck('id'))->unique()->values();
        $before = [
            'factory_id' => $target->factory_id,
            'department_id' => $target->department_id,
            'additional' => $current->pluck('id')->sort()->values()->all(),
        ];

        DB::transaction(function () use ($target, $factoryId, $primaryDepartmentId, $finalAdditional, $actor) {
            $target->forceFill(['factory_id' => $factoryId, 'department_id' => $primaryDepartmentId])->save();
            $target->additionalDepartments()->sync(
                $finalAdditional->mapWithKeys(fn ($id) => [$id => ['assigned_by' => $actor->id]])->all()
            );
        });
        $this->scope->forget();

        return array_filter([
            'factory' => $before['factory_id'] !== $factoryId ? ['from' => $before['factory_id'], 'to' => $factoryId] : null,
            'primary_department' => $before['department_id'] !== $primaryDepartmentId ? ['from' => $before['department_id'], 'to' => $primaryDepartmentId] : null,
            'departments_added' => $finalAdditional->diff($before['additional'])->values()->all() ?: null,
            'departments_removed' => collect($before['additional'])->diff($finalAdditional)->values()->all() ?: null,
        ]);
    }

    /**
     * Replaces the member list of a department. New members without a
     * primary department get this one as primary; others get it as an
     * additional department. Removing a member clears whichever link exists.
     *
     * @param  list<int>  $userIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function setDepartmentMembers(User $actor, Department $department, array $userIds): array
    {
        $currentIds = $department->members()->pluck('id')->all();
        $added = array_values(array_diff($userIds, $currentIds));
        $removed = array_values(array_diff($currentIds, $userIds));

        if (! $this->scope->hasGlobalAccess($actor) && in_array($actor->id, [...$added, ...$removed], true)) {
            throw new AuthorizationException('You cannot change your own department assignment.');
        }
        if ($added && ! $department->is_active) {
            $this->fail('user_ids', 'Inactive departments cannot receive new assignments.');
        }
        if ($added && ! $department->factory->is_active) {
            $this->fail('user_ids', 'Departments in an inactive factory cannot receive new assignments.');
        }

        /** @var Collection<int, User> $newUsers */
        $newUsers = User::query()->whereKey($added)->get();
        $inactive = $newUsers->where('is_active', false);
        if ($inactive->isNotEmpty()) {
            $this->fail('user_ids', 'Inactive users cannot be assigned: '.$inactive->pluck('full_name')->implode(', '));
        }

        DB::transaction(function () use ($department, $newUsers, $removed, $actor) {
            foreach ($newUsers as $user) {
                if ($user->department_id === null) {
                    $user->forceFill([
                        'department_id' => $department->id,
                        'factory_id' => $user->factory_id ?? $department->factory_id,
                    ])->save();
                } else {
                    $user->additionalDepartments()->syncWithoutDetaching([$department->id => ['assigned_by' => $actor->id]]);
                }
            }

            User::query()->whereKey($removed)->where('department_id', $department->id)->update(['department_id' => null]);
            $department->additionalUsers()->detach($removed);
        });
        $this->scope->forget();

        return ['added' => $added, 'removed' => $removed];
    }

    private function ensureMayChange(User $actor, User $target): void
    {
        if ($actor->is($target) && ! $this->scope->hasGlobalAccess($actor)) {
            throw new AuthorizationException('You cannot change your own factory or department assignment.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
