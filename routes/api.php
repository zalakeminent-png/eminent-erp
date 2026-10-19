<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
| All routes are authorized by permission (module.action), never by role name.
| The SPA authenticates with Sanctum's stateful cookie session.
*/

Route::prefix('v1')->group(function () {
    // ---- Guest authentication ------------------------------------------
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    });

    // ---- Authenticated ---------------------------------------------------
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::put('change-password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1');
        });

        Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');

        Route::get('company', [CompanyController::class, 'show'])->middleware('permission:company.view');
        Route::put('company', [CompanyController::class, 'update'])->middleware('permission:company.update');

        Route::controller(UserController::class)->prefix('users')->group(function () {
            Route::get('/', 'index')->middleware('permission:users.view');
            Route::post('/', 'store')->middleware('permission:users.create');
            Route::get('{user}', 'show')->middleware('permission:users.view');
            Route::put('{user}', 'update')->middleware('permission:users.update');
            // Activate vs deactivate permission is checked in the controller.
            Route::patch('{user}/status', 'updateStatus')->middleware('permission:users.activate|users.deactivate');
            Route::post('{user}/send-password-reset', 'sendPasswordReset')->middleware(['permission:users.reset-password', 'throttle:6,1']);
        });

        Route::controller(RoleController::class)->prefix('roles')->group(function () {
            // Role names are also needed by user forms and filters.
            Route::get('/', 'index')->middleware('permission:roles.view|users.view|users.create|users.update');
            Route::post('/', 'store')->middleware('permission:roles.create');
            Route::get('{role}', 'show')->middleware('permission:roles.view');
            Route::put('{role}', 'update')->middleware('permission:roles.update');
            Route::delete('{role}', 'destroy')->middleware('permission:roles.delete');
            Route::get('{role}/permissions', 'permissions')->middleware('permission:roles.view');
            Route::put('{role}/permissions', 'syncPermissions')->middleware('permission:roles.assign-permissions');
        });

        // Catalogue is also needed to render the (read-only) role matrix.
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:permissions.view|roles.view|roles.assign-permissions');

        Route::get('activity-logs', [ActivityLogController::class, 'index'])->middleware('permission:activity-logs.view');
    });
});
