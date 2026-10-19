<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where a user works (factory / departments). Role assignment, i.e. what a
 * user may do, stays in the Module 1 user endpoints.
 */
class UserAssignmentController extends Controller
{
    public function __construct(
        private readonly StructureAssignmentService $assignments,
        private readonly ActivityLogger $activity,
    ) {}

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['roles', 'primaryFactory', 'primaryDepartment', 'additionalDepartments']));
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'factory_id' => ['present', 'nullable', 'integer', Rule::exists('factories', 'id')],
            'primary_department_id' => ['present', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'department_ids' => ['present', 'array', 'max:50'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')],
        ]);

        $changes = $this->assignments->assignUser(
            $request->user(),
            $user,
            $data['factory_id'],
            $data['primary_department_id'],
            array_map('intval', $data['department_ids']),
        );

        if ($changes) {
            $this->activity->log('user.assignments_changed', $user, $changes, "Changed factory/department assignment of {$user->full_name}");
        }

        return new UserResource($user->fresh(['roles', 'primaryFactory', 'primaryDepartment', 'additionalDepartments']));
    }
}
