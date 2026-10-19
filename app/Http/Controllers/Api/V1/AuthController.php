<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\UserSessions;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly UserSessions $sessions,
    ) {}

    public function login(LoginRequest $request): AuthUserResource
    {
        $key = $request->throttleKey();
        $email = Str::lower($request->string('email'));

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ])->status(429);
        }

        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $request->input('password')], $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            // Throttled attempts return above, which also caps log volume.
            $this->activity->log('auth.login_failed', null, ['email' => $email], 'Failed login attempt');

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $this->activity->log('auth.login_blocked', $user, [], 'Login attempt on inactive account', actor: $user);

            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Contact your administrator.',
            ])->status(403);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        User::query()->whereKey($user->id)->toBase()->update(['last_login_at' => now()]);
        $user->refresh();

        $this->activity->log('auth.login', $user, [], 'Signed in', actor: $user);

        return new AuthUserResource($user->load('roles', 'company'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->activity->log('auth.logout', $user, [], 'Signed out');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): AuthUserResource
    {
        return new AuthUserResource($request->user()->load('roles', 'company'));
    }

    public function updateProfile(UpdateProfileRequest $request): AuthUserResource
    {
        $user = $request->user();
        $before = $user->only(array_keys($request->validated()));
        $user->update($request->validated());

        $changes = $this->activity->diff($before, $user->only(array_keys($request->validated())));
        if ($changes) {
            $this->activity->log('profile.updated', $user, ['changes' => $changes], 'Updated own profile');
        }

        return new AuthUserResource($user->load('roles', 'company'));
    }

    /**
     * Always returns the same response so the endpoint cannot be used to
     * discover which email addresses have accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if ($user?->is_active) {
            $status = Password::sendResetLink(['email' => $user->email]);
            if ($status === Password::RESET_LINK_SENT) {
                $this->activity->log('auth.password_reset_requested', $user, [], 'Password reset link requested', actor: $user);
            }
        }

        return response()->json([
            'message' => 'If an active account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        $credentials['email'] = Str::lower($credentials['email']);

        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $this->sessions->terminate($user);
            event(new PasswordReset($user));
            $this->activity->log('auth.password_reset', $user, [], 'Password reset via emailed link', actor: $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => trans($status)]);
        }

        return response()->json(['message' => 'Your password has been set. You can now sign in.']);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->input('password')])->save();

        // Sign out every other device; keep (and rotate) this session.
        $this->sessions->terminate($user, $request->session()->getId());
        $request->session()->regenerate();

        $this->activity->log('auth.password_changed', $user, [], 'Changed own password');

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
