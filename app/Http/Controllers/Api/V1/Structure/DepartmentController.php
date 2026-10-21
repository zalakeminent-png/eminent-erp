<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\DepartmentRequest;
use App\Http\Resources\Structure\DepartmentResource;
use App\Http\Resources\UserResource;
use App\Models\Department;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureAssignmentService;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use ChangesStatus;

    private const SORTABLE = ['code', 'name', 'is_active', 'created_at'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly StructureScope $scope,
        private readonly StructureAssignmentService $assignments,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'factory_id' => ['nullable', 'integer'],
            'type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = $this->scope->applyByFactory(Department::query(), $request->user())
            ->with(['factory', 'type', 'manager', 'supervisor'])
            ->withCount('warehouses')
            ->tap(fn (Builder $q) => $this->withMembersCount($q))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(departments.code) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(departments.name) LIKE ?', [Search::like($s)])))
            ->when($filters['factory_id'] ?? null, fn ($q, $id) => $q->where('factory_id', $id))
            ->when($filters['type_id'] ?? null, fn ($q, $id) => $q->where('type_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('departments.is_active', $s === 'active'))
            ->orderBy('departments.'.($filters['sort'] ?? 'name'), $filters['direction'] ?? 'asc')
            ->orderBy('departments.id');

        if ($request->boolean('all')) {
            return DepartmentResource::collection($query->limit(1000)->get());
        }

        return DepartmentResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $assignsLeaders = ! empty($data['manager_id']) || ! empty($data['supervisor_id']);
        abort_if($assignsLeaders && ! $request->user()->can('departments.assign-managers'), 403, 'You may not assign department managers or supervisors.');

        $department = DB::transaction(function () use ($data, $request) {
            $department = new Department(collect($data)->except(['manager_id', 'supervisor_id'])->all());
            $department->is_active = $request->boolean('is_active', true);
            $department->manager_id = $data['manager_id'] ?? null;
            $department->supervisor_id = $data['supervisor_id'] ?? null;
            $department->save();

            return $department;
        });

        $this->activity->log('department.created', $department, [
            'code' => $department->code, 'name' => $department->name, 'factory_id' => $department->factory_id,
            'manager_id' => $department->manager_id, 'supervisor_id' => $department->supervisor_id,
        ], "Created department {$department->name}");

        return (new DepartmentResource($this->detail($department)))
            ->additional(['message' => 'Department created.'])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Department $department): DepartmentResource
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);

        return new DepartmentResource($this->detail($department));
    }

    public function update(DepartmentRequest $request, Department $department): DepartmentResource
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);

        $data = collect($request->validated())->except('factory_id')->all();
        $before = $department->only(array_keys($data));
        $department->update($data);

        if ($changes = $this->activity->diff($before, $department->only(array_keys($data)))) {
            $this->activity->log('department.updated', $department, ['changes' => $changes], "Updated department {$department->name}");
        }

        return new DepartmentResource($this->detail($department));
    }

    public function updateStatus(Request $request, Department $department): DepartmentResource
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);

        $this->changeStatus($request, $department, 'departments', 'department', fn (Department $d, bool $activate) => $activate && ! $d->factory->is_active
            ? 'Activate the factory before activating this department.'
            : null);

        return new DepartmentResource($this->detail($department));
    }

    /**
     * Permanently deletes a department that nothing refers to. Departments
     * with employees or linked records must be reassigned (or deactivated).
     */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);

        $exists = fn (string $table, string $column) => DB::table($table)->where($column, $department->id)->exists();
        $blockers = array_values(array_filter([
            $exists('users', 'department_id') || $exists('department_user', 'department_id') ? 'it has assigned employees' : null,
            $exists('warehouses', 'department_id') ? 'storage areas are linked to it' : null,
            $exists('machines', 'department_id') ? 'machines are assigned to it' : null,
            $exists('manufacturing_processes', 'default_department_id') || $exists('routing_operations', 'department_id')
                ? 'manufacturing processes or routings use it' : null,
            $exists('inventory_documents', 'department_id') ? 'stock documents refer to it' : null,
        ]));
        if ($blockers) {
            return response()->json([
                'message' => "{$department->name} cannot be deleted because ".implode(' and ', $blockers).'. Reassign these first, or deactivate the department instead.',
            ], 409);
        }

        $snapshot = $department->only(['id', 'code', 'name', 'factory_id']);
        $department->delete();
        $this->activity->log('department.deleted', null, $snapshot, "Deleted department {$snapshot['name']} ({$snapshot['code']})");

        return response()->json(['message' => "{$snapshot['name']} has been deleted."]);
    }

    /** Department Employees: primary and additional members. */
    public function users(Request $request, Department $department): AnonymousResourceCollection
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:5,200'],
        ]);

        $users = $department->members()
            ->with(['roles', 'primaryDepartment'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [Search::like($s)])
                ->orWhereRaw('LOWER(email) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(employee_code) LIKE ?', [Search::like($s)])))
            ->orderBy('first_name')->orderBy('last_name')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return UserResource::collection($users)->additional([
            'meta' => ['primary_user_ids' => $users->getCollection()->where('department_id', $department->id)->pluck('id')->values()],
        ]);
    }

    public function syncUsers(Request $request, Department $department): JsonResponse
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:1000'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')],
        ]);

        $result = $this->assignments->setDepartmentMembers($request->user(), $department->load('factory'), $data['user_ids']);

        if ($result['added'] || $result['removed']) {
            $this->activity->log('department.users_changed', $department, $result, "Changed employees of department {$department->name}");
        }

        return response()->json([
            'message' => 'Department employees updated.',
            'data' => $result,
        ]);
    }

    /** Assigns or clears the department manager and supervisor. */
    public function assignLeaders(Request $request, Department $department): DepartmentResource
    {
        $this->scope->ensureFactory($request->user(), $department->factory_id);

        // Inactive users can never be (re)confirmed as manager or supervisor.
        $activeUser = Rule::exists('users', 'id')->where('is_active', true);

        $data = $request->validate([
            'manager_id' => ['present', 'nullable', 'integer', $activeUser],
            'supervisor_id' => ['present', 'nullable', 'integer', $activeUser],
        ], [
            'manager_id.exists' => 'The manager must be an active user.',
            'supervisor_id.exists' => 'The supervisor must be an active user.',
        ]);

        $before = $department->only(['manager_id', 'supervisor_id']);
        $department->forceFill($data)->save();

        if ($changes = $this->activity->diff($before, $department->only(['manager_id', 'supervisor_id']))) {
            $this->activity->log('department.leaders_changed', $department, ['changes' => $changes], "Changed manager/supervisor of department {$department->name}");
        }

        return new DepartmentResource($this->detail($department));
    }

    private function detail(Department $department): Department
    {
        $department->load(['factory', 'type', 'manager', 'supervisor', 'creator'])->loadCount('warehouses');
        $department->setAttribute('members_count', $department->members()->count());

        return $department;
    }

    private function withMembersCount(Builder $query): void
    {
        $query->select('departments.*')
            ->selectSub(fn ($q) => $q->from('users')->selectRaw('count(*)')
                ->whereColumn('users.department_id', 'departments.id')
                ->orWhereExists(fn ($e) => $e->from('department_user')
                    ->whereColumn('department_user.user_id', 'users.id')
                    ->whereColumn('department_user.department_id', 'departments.id')), 'members_count');
    }
}
