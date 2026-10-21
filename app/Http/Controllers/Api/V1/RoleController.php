<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Services\AccessGuard;
use App\Services\ActivityLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly AccessGuard $guard,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'all' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $query = Role::query()
            ->where('guard_name', PermissionCatalog::GUARD)
            ->withCount(['users', 'permissions'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(addcslashes($search, '%_\\')).'%']))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->orderByDesc('is_system')
            ->orderBy('name');

        // Lightweight full list for dropdowns (user form, filters).
        if ($request->boolean('all')) {
            return RoleResource::collection($query->get());
        }

        return RoleResource::collection($query->paginate($filters['per_page'] ?? 15)->withQueryString());
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $permissions = $request->input('permissions', []);
        $this->guard->ensureActorHoldsPermissions($request->user(), $permissions);

        $role = DB::transaction(function () use ($request, $permissions) {
            $role = Role::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'guard_name' => PermissionCatalog::GUARD,
                'is_system' => false,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $role->syncPermissions($permissions);

            return $role;
        });

        $this->activity->log('role.created', $role, [
            'name' => $role->name,
            'permissions' => $permissions,
        ], "Created role {$role->name}");

        return (new RoleResource($role->load('permissions')->loadCount(['users', 'permissions'])))
            ->additional(['message' => 'Role created.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions')->loadCount(['users', 'permissions']);
        $users = $role->users()->with('roles')->orderBy('first_name')->orderBy('last_name')->limit(500)->get();

        return (new RoleResource($role))
            ->additional(['users' => UserResource::collection($users)])
            ->response();
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', $role->is_active);

        if ($role->is_system && $data['name'] !== $role->name) {
            throw ValidationException::withMessages(['name' => 'Core roles cannot be renamed.']);
        }
        if ($role->isPrimaryAdmin() && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'The primary administrator role cannot be deactivated.']);
        }

        $before = $role->only(array_keys($data));
        $role->update($data);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('role.updated', $role, [
            'changes' => $this->activity->diff($before, $role->only(array_keys($data))),
        ], "Updated role {$role->name}");

        return new RoleResource($role->load('permissions')->loadCount(['users', 'permissions']));
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Core roles cannot be deleted.']);
        }

        $assigned = $role->users()->count();
        if ($assigned > 0) {
            return response()->json([
                'message' => "This role is assigned to {$assigned} user(s). Reassign them to another role before deleting it.",
            ], 409);
        }

        $name = $role->name;
        $id = $role->id;
        DB::transaction(fn () => $role->delete());

        $this->activity->log('role.deleted', null, ['role_id' => $id, 'name' => $name], "Deleted role {$name}");

        return response()->json(['message' => 'Role deleted.']);
    }

    public function permissions(Role $role): JsonResponse
    {
        return response()->json([
            'data' => [
                'role' => new RoleResource($role),
                'permissions' => $role->permissions()->pluck('name')->sort()->values(),
            ],
        ]);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $actor = $request->user();

        if ($role->isPrimaryAdmin()) {
            throw ValidationException::withMessages([
                'permissions' => 'The primary administrator role always holds every permission and cannot be edited.',
            ]);
        }
        $this->guard->ensureNotOwnRole($actor, $role);

        $current = $role->permissions()->pluck('name');
        // Permissions of modules hidden from the matrix are kept as they are.
        $hidden = collect(app(PermissionCatalog::class)->modules())->filter(fn (array $d) => $d['hidden'] ?? false)
            ->flatMap(fn (array $d, string $key) => array_map(fn ($a) => "{$key}.{$a}", $d['actions']));
        $requested = collect($request->input('permissions'))->merge($current->intersect($hidden))->unique()->values();
        $added = $requested->diff($current)->values();
        $removed = $current->diff($requested)->values();

        // Both granting and revoking require holding the permission.
        $this->guard->ensureActorHoldsPermissions($actor, $added->merge($removed));

        DB::transaction(fn () => $role->syncPermissions($requested->all()));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($added->isNotEmpty() || $removed->isNotEmpty()) {
            $this->activity->log('role.permissions_updated', $role, [
                'added' => $added->all(),
                'removed' => $removed->all(),
            ], "Updated permissions for role {$role->name}");
        }

        return response()->json([
            'message' => 'Permissions saved.',
            'data' => [
                'role' => new RoleResource($role->loadCount(['users', 'permissions'])),
                'permissions' => $requested->sort()->values(),
            ],
        ]);
    }
}
