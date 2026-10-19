<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * The permission catalogue shaped for the role-permission matrix:
     * modules as rows, actions as columns.
     */
    public function index(PermissionCatalog $catalog): JsonResponse
    {
        $existing = Permission::query()->where('guard_name', PermissionCatalog::GUARD)->pluck('name')->flip();
        $standard = config('erp.standard_actions');

        $modules = collect($catalog->modules())->map(fn (array $definition, string $key) => [
            'key' => $key,
            'label' => $definition['label'],
            'group' => $definition['group'],
            'reserved' => (bool) ($definition['reserved'] ?? false),
            'permissions' => collect($definition['actions'])
                ->filter(fn ($action) => $existing->has("{$key}.{$action}"))
                ->map(fn ($action) => [
                    'name' => "{$key}.{$action}",
                    'action' => $action,
                    'standard' => in_array($action, $standard, true),
                ])->values(),
        ])->values();

        return response()->json([
            'data' => [
                'standard_actions' => $standard,
                'modules' => $modules,
            ],
        ]);
    }
}
