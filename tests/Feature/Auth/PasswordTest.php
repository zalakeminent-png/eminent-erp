<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    private const NEW_PASSWORD = 'N3w-Secure#Pass';

    public function test_password_reset_workflow(): void
    {
        Notification::fake();
        $user = $this->createUserWithRole('QC', ['email' => 'qc@factory.test']);

        $this->postJson($this->api('auth/forgot-password'), ['email' => 'qc@factory.test'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token, $user) {
            $token = $notification->token;
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password?token=') && str_contains($url, 'email=qc%40factory.test');
        });

        // Wrong token is rejected.
        $this->postJson($this->api('auth/reset-password'), [
            'token' => 'invalid', 'email' => 'qc@factory.test',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable();

        $this->postJson($this->api('auth/reset-password'), [
            'token' => $token, 'email' => 'qc@factory.test',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        // Token is single-use.
        $this->postJson($this->api('auth/reset-password'), [
            'token' => $token, 'email' => 'qc@factory.test',
            'password' => 'An0ther#Password', 'password_confirmation' => 'An0ther#Password',
        ])->assertUnprocessable();

        $this->postJson($this->api('auth/login'), ['email' => 'qc@factory.test', 'password' => self::NEW_PASSWORD])->assertOk();

        // The audit trail never contains the token or password.
        $logs = ActivityLog::all()->toJson();
        $this->assertStringNotContainsString($token, $logs);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $logs);
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.password_reset', 'entity_id' => $user->id]);
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $known = $this->createUserWithRole('QC', ['email' => 'qc@factory.test']);
        $a = $this->postJson($this->api('auth/forgot-password'), ['email' => 'qc@factory.test'])->assertOk()->json('message');
        $b = $this->postJson($this->api('auth/forgot-password'), ['email' => 'nobody@factory.test'])->assertOk()->json('message');

        $this->assertSame($a, $b);
        Notification::assertSentTo($known, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_reset_password_enforces_strength(): void
    {
        $this->postJson($this->api('auth/reset-password'), [
            'token' => 'x', 'email' => 'qc@factory.test',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_invited_user_sets_password_from_invitation(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $roleId = \App\Models\Role::findByName('QC')->id;

        $this->actingAs($admin)->postJson($this->api('users'), [
            'first_name' => 'Ravi', 'last_name' => 'Kumar', 'email' => 'ravi@factory.test', 'role_ids' => [$roleId],
        ])->assertCreated()->assertJsonPath('meta.invitation_sent', true);

        $invitee = User::where('email', 'ravi@factory.test')->sole();
        $token = null;
        Notification::assertSentTo($invitee, UserInvitationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->app['auth']->forgetGuards();
        $this->postJson($this->api('auth/reset-password'), [
            'token' => $token, 'email' => 'ravi@factory.test',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson($this->api('auth/login'), ['email' => 'ravi@factory.test', 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_user_can_change_password(): void
    {
        $user = $this->createUserWithRole('Accountant');

        $this->actingAs($user)->putJson($this->api('auth/change-password'), [
            'current_password' => 'wrong', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->putJson($this->api('auth/change-password'), [
            'current_password' => 'Password@123', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.password_changed', 'actor_id' => $user->id]);
    }

    public function test_admin_can_send_password_reset_to_user(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $user = $this->createUserWithRole('Store Keeper');

        $this->actingAs($admin)->postJson($this->api("users/{$user->id}/send-password-reset"))->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.password_reset_sent', 'entity_id' => $user->id]);
    }
}
