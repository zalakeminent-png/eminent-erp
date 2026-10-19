<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revokes a user's server-side sessions (database session driver). Used on
 * deactivation and password changes so existing logins stop working.
 * With other drivers, the EnsureUserIsActive middleware and the password
 * hash check performed by Laravel still apply on the next request.
 */
class UserSessions
{
    public function terminate(User $user, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
