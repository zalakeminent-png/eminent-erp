<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\Structure\DepartmentController;
use App\Http\Controllers\Api\V1\Structure\FactoryController;
use App\Http\Controllers\Api\V1\Structure\FactoryStructureController;
use App\Http\Controllers\Api\V1\Structure\LocationController;
use App\Http\Controllers\Api\V1\Structure\LookupController;
use App\Http\Controllers\Api\V1\Structure\StructureTypeController;
use App\Http\Controllers\Api\V1\Structure\UserAssignmentController;
use App\Http\Controllers\Api\V1\Structure\WarehouseController;
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
            Route::delete('{user}', 'destroy')->middleware('permission:users.delete');
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

        // ---- Module 2: Factory & Department Management ----------------
        // Results are additionally limited to the user's factory data scope
        // (StructureScope); dropdown sources accept related form permissions.

        Route::controller(FactoryController::class)->prefix('factories')->group(function () {
            Route::get('/', 'index')->middleware('permission:factories.view|departments.view|warehouses.view|locations.view|departments.assign-users');
            Route::post('/', 'store')->middleware('permission:factories.create');
            Route::get('{factory}', 'show')->middleware('permission:factories.view');
            Route::put('{factory}', 'update')->middleware('permission:factories.update');
            Route::patch('{factory}/status', 'updateStatus')->middleware('permission:factories.update|factories.deactivate');
        });

        Route::controller(DepartmentController::class)->prefix('departments')->group(function () {
            Route::get('/', 'index')->middleware('permission:departments.view|warehouses.create|warehouses.update|departments.assign-users|machines.create|machines.update|processes.create|processes.update|routings.create|routings.update');
            Route::post('/', 'store')->middleware('permission:departments.create');
            Route::get('{department}', 'show')->middleware('permission:departments.view');
            Route::put('{department}', 'update')->middleware('permission:departments.update');
            Route::patch('{department}/status', 'updateStatus')->middleware('permission:departments.update|departments.deactivate');
            Route::get('{department}/users', 'users')->middleware('permission:departments.view');
            Route::put('{department}/users', 'syncUsers')->middleware('permission:departments.assign-users');
            Route::put('{department}/supervisor', 'assignLeaders')->middleware('permission:departments.assign-managers');
        });

        Route::controller(WarehouseController::class)->prefix('warehouses')->group(function () {
            Route::get('/', 'index')->middleware('permission:warehouses.view|locations.view|locations.create');
            Route::post('/', 'store')->middleware('permission:warehouses.create');
            Route::get('{warehouse}', 'show')->middleware('permission:warehouses.view');
            Route::put('{warehouse}', 'update')->middleware('permission:warehouses.update');
            Route::patch('{warehouse}/status', 'updateStatus')->middleware('permission:warehouses.update|warehouses.deactivate');
        });
        Route::get('warehouses/{warehouse}/locations/tree', [LocationController::class, 'tree'])->middleware('permission:locations.view');

        Route::controller(LocationController::class)->prefix('locations')->group(function () {
            Route::get('/', 'index')->middleware('permission:locations.view');
            Route::post('/', 'store')->middleware('permission:locations.create');
            Route::get('{location}', 'show')->middleware('permission:locations.view');
            Route::put('{location}', 'update')->middleware('permission:locations.update');
            Route::patch('{location}/status', 'updateStatus')->middleware('permission:locations.update|locations.deactivate');
            Route::delete('{location}', 'destroy')->middleware('permission:locations.delete');
        });

        Route::controller(FactoryStructureController::class)->prefix('factory-structure')->group(function () {
            Route::get('/', 'index')->middleware('permission:factory-structure.view');
            Route::get('export', 'export')->middleware('permission:factory-structure.export');
            Route::get('{factory}', 'show')->middleware('permission:factory-structure.view');
        });

        Route::controller(StructureTypeController::class)->prefix('structure-types')->group(function () {
            Route::get('/', 'index')->middleware('permission:structure-types.view|departments.create|departments.update|warehouses.create|warehouses.update');
            Route::post('/', 'store')->middleware('permission:structure-types.create');
            Route::put('{structureType}', 'update')->middleware('permission:structure-types.update');
        });

        Route::get('users/{user}/assignments', [UserAssignmentController::class, 'show'])->middleware('permission:users.view|departments.assign-users');
        Route::put('users/{user}/assignments', [UserAssignmentController::class, 'update'])->middleware('permission:departments.assign-users');

        Route::get('lookups/users', [LookupController::class, 'users'])
            ->middleware('permission:departments.assign-managers|departments.assign-users|departments.create|warehouses.create|warehouses.update');

        // ---- Module 3: Master Data Management -------------------------
        require __DIR__.'/master-data.php';
        require __DIR__.'/inventory.php';
    });
});
