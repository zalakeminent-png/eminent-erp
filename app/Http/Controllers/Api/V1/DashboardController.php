<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Real database counts only — no invented manufacturing metrics.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $recent = $user->can('activity-logs.view')
            ? ActivityLogResource::collection(
                ActivityLog::query()->with('actor')->latest('created_at')->latest('id')->limit(10)->get()
            )
            : null;

        return response()->json([
            'data' => [
                'company_name' => Company::current()?->name,
                'stats' => [
                    'active_users' => User::query()->where('is_active', true)->count(),
                    'inactive_users' => User::query()->where('is_active', false)->count(),
                    'total_roles' => Role::query()->where('guard_name', 'web')->count(),
                ],
                'recent_activity' => $recent,
            ],
        ]);
    }
}
