<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessGuard;
use App\Services\ActivityLogger;
use App\Services\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const SORTABLE = ['name', 'email', 'employee_code', 'is_active', 'last_login_at', 'created_at'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly AccessGuard $guard,
        private readonly UserSessions $sessions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $direction = $filters['direction'] ?? 'asc';
        $sort = $filters['sort'] ?? 'name';

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(first_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                        ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(employee_code) LIKE ?', [$term]);
                });
            })
            ->when($filters['role_id'] ?? null, fn ($query, $roleId) => $query->whereHas('roles', fn ($q) => $q->whereKey($roleId)))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->when($sort === 'name',
                fn ($query) => $query->orderBy('first_name', $direction)->orderBy('last_name', $direction),
                fn ($query) => $query->orderBy($sort, $direction))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        $roles = Role::query()->whereKey($request->input('role_ids'))->get();
        $this->guard->ensureCanAssignRoles($actor, $roles);

        $user = DB::transaction(function () use ($request, $roles) {
            $user = new User($request->safe()->except('role_ids'));
            $user->company_id = Company::current()?->id;
            // Unknown random password: the account is unusable until the
            // invitee sets their own password via the emailed link.
            $user->password = Str::random(64);
            $user->is_active = $request->boolean('is_active', true);
            $user->save();
            $user->syncRoles($roles);

            return $user;
        });

        $this->activity->log('user.created', $user, [
            'email' => $user->email,
            'roles' => $roles->pluck('name')->all(),
            'is_active' => $user->is_active,
        ], "Created user {$user->full_name}");

        $invitationSent = $user->is_active && $this->sendInvitation($user);

        return (new UserResource($user->load('roles')))
            ->additional([
                'message' => $invitationSent
                    ? 'User created. A password setup link has been emailed.'
                    : 'User created. The password setup email could not be sent; use "Send password reset" to retry.',
                'meta' => ['invitation_sent' => $invitationSent],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $actor = $request->user();
        $user->load('roles');
        $this->guard->ensureCanManage($actor, $user);

        $newRoles = Role::query()->whereKey($request->input('role_ids'))->get();
        $currentIds = $user->roles->modelKeys();
        $changedRoleIds = collect($newRoles->modelKeys())->diff($currentIds)
            ->merge(collect($currentIds)->diff($newRoles->modelKeys()));
        $rolesChanged = $changedRoleIds->isNotEmpty();

        if ($rolesChanged) {
            $this->guard->ensureNotSelf($actor, $user, 'You cannot change your own roles.');
            $this->guard->ensureCanAssignRoles($actor, Role::query()->whereKey($changedRoleIds)->get());
            $this->guard->ensureAdministratorRemains($user, null, $newRoles);
        }

        $fields = $request->safe()->except('role_ids');
        $before = $user->only(array_keys($fields));
        $beforeRoles = $user->roles->pluck('name')->all();

        DB::transaction(function () use ($user, $fields, $newRoles, $rolesChanged) {
            $user->update($fields);
            if ($rolesChanged) {
                $user->syncRoles($newRoles);
            }
        });

        $metadata = ['changes' => $this->activity->diff($before, $user->only(array_keys($fields)))];
        if ($rolesChanged) {
            $metadata['roles'] = ['from' => $beforeRoles, 'to' => $newRoles->pluck('name')->all()];
        }
        $this->activity->log('user.updated', $user, $metadata, "Updated user {$user->full_name}");

        return new UserResource($user->fresh('roles'));
    }

    public function updateStatus(Request $request, User $user): UserResource
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $activate = (bool) $data['is_active'];
        $actor = $request->user();

        abort_unless($actor->can($activate ? 'users.activate' : 'users.deactivate'), 403);
        $this->guard->ensureNotSelf($actor, $user, 'You cannot change the status of your own account.');
        $user->load('roles');
        $this->guard->ensureCanManage($actor, $user);

        if ($user->is_active === $activate) {
            return new UserResource($user);
        }

        if (! $activate) {
            $this->guard->ensureAdministratorRemains($user, false);
        }

        $user->forceFill([
            'is_active' => $activate,
            'deactivated_at' => $activate ? null : now(),
        ])->save();

        if (! $activate) {
            $this->sessions->terminate($user);
        }

        $this->activity->log(
            $activate ? 'user.activated' : 'user.deactivated',
            $user,
            [],
            ($activate ? 'Activated' : 'Deactivated')." user {$user->full_name}",
        );

        return new UserResource($user);
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->guard->ensureCanManage($request->user(), $user->load('roles'));

        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => 'Activate the user before sending a password reset link.']);
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_THROTTLED) {
            return response()->json(['message' => 'A reset link was sent recently. Please wait before trying again.'], 429);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'The password reset link could not be sent.'], 500);
        }

        $this->activity->log('user.password_reset_sent', $user, [], "Sent password reset link to {$user->full_name}");

        return response()->json(['message' => "A password reset link has been emailed to {$user->email}."]);
    }

    private function sendInvitation(User $user): bool
    {
        try {
            $user->sendInvitationNotification(Password::broker()->createToken($user));

            return true;
        } catch (\Throwable $e) {
            Log::error('User invitation email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
