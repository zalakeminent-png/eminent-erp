<?php

use App\Http\Controllers\Api\V1\MasterData\BomController;
use App\Http\Controllers\Api\V1\MasterData\BusinessPartnerController;
use App\Http\Controllers\Api\V1\MasterData\DocumentController;
use App\Http\Controllers\Api\V1\MasterData\ImportController;
use App\Http\Controllers\Api\V1\MasterData\ItemAttributeController;
use App\Http\Controllers\Api\V1\MasterData\ItemCategoryController;
use App\Http\Controllers\Api\V1\MasterData\ItemController;
use App\Http\Controllers\Api\V1\MasterData\MachineController;
use App\Http\Controllers\Api\V1\MasterData\MasterDataDashboardController;
use App\Http\Controllers\Api\V1\MasterData\MaterialFamilyController;
use App\Http\Controllers\Api\V1\MasterData\MaterialGradeController;
use App\Http\Controllers\Api\V1\MasterData\ProcessController;
use App\Http\Controllers\Api\V1\MasterData\QcParameterController;
use App\Http\Controllers\Api\V1\MasterData\QcSpecificationController;
use App\Http\Controllers\Api\V1\MasterData\RoutingController;
use App\Http\Controllers\Api\V1\MasterData\UomController;
use App\Http\Controllers\Api\V1\MasterData\UomConversionController;
use Illuminate\Support\Facades\Route;

/*
| Module 3 — Master Data. Included inside the authenticated /api/v1 group.
| Read endpoints also accept the permissions of forms that need them as
| dropdown sources (e.g. units for the item form). Every write endpoint
| requires its own explicit permission.
*/

// Simple masters: index/show/store/update/status share one shape.
$master = function (string $prefix, string $controller, string $read, string $create, string $update, string $status) {
    Route::controller($controller)->prefix($prefix)->whereNumber('id')->group(function () use ($read, $create, $update, $status) {
        Route::get('/', 'index')->middleware("permission:{$read}");
        Route::post('/', 'store')->middleware("permission:{$create}");
        Route::get('{id}', 'show')->middleware("permission:{$read}");
        Route::put('{id}', 'update')->middleware("permission:{$update}");
        // Deactivate vs. reactivate permission is checked in the controller.
        Route::patch('{id}/status', 'updateStatus')->middleware("permission:{$status}");
    });
};

Route::get('master-data/dashboard', MasterDataDashboardController::class)->middleware('permission:master-data.view');

Route::get('uom-conversions/convert', [UomConversionController::class, 'convert'])->middleware('permission:uoms.view|items.view|boms.view');
$master('uoms', UomController::class, 'uoms.view|items.view|items.create|items.update|boms.view|qc-specifications.view|machines.view|master-data.view', 'uoms.create', 'uoms.update', 'uoms.update');
$master('uom-conversions', UomConversionController::class, 'uoms.view', 'uoms.create', 'uoms.update', 'uoms.update');

Route::get('item-categories/tree', [ItemCategoryController::class, 'tree'])->middleware('permission:item-categories.view|items.view|items.create|items.update');
Route::delete('item-categories/{id}', [ItemCategoryController::class, 'destroy'])->whereNumber('id')->middleware('permission:item-categories.deactivate');
$master('item-categories', ItemCategoryController::class, 'item-categories.view|items.view|items.create|items.update', 'item-categories.create', 'item-categories.update', 'item-categories.update|item-categories.deactivate');
$master('item-attributes', ItemAttributeController::class, 'item-categories.view|items.view|items.create|items.update', 'item-categories.create', 'item-categories.update', 'item-categories.update');

$master('material-families', MaterialFamilyController::class, 'material-grades.view|items.view', 'material-grades.create', 'material-grades.update', 'material-grades.update');
$master('material-grades', MaterialGradeController::class, 'material-grades.view|items.view|items.create|items.update', 'material-grades.create', 'material-grades.update', 'material-grades.update');

$master('manufacturing-processes', ProcessController::class, 'processes.view|machines.view|machines.create|machines.update|routings.view|qc-specifications.view', 'processes.create', 'processes.update', 'processes.update');
$master('machines', MachineController::class, 'machines.view|routings.view|routings.create|routings.update', 'machines.create', 'machines.update', 'machines.update');
$master('qc-parameters', QcParameterController::class, 'qc-specifications.view', 'qc-specifications.create', 'qc-specifications.update', 'qc-specifications.update');
$master('business-partners', BusinessPartnerController::class, 'business-partners.view', 'business-partners.create', 'business-partners.update', 'business-partners.update|business-partners.deactivate');

// Items
Route::controller(ItemController::class)->prefix('items')->whereNumber(['id', 'conversionId'])->group(function () {
    $read = 'permission:items.view|boms.view|boms.create|boms.update|routings.view|routings.create|qc-specifications.view|qc-specifications.create';
    Route::get('/', 'index')->middleware($read);
    Route::get('export', 'export')->middleware('permission:items.export');
    Route::get('next-code', 'nextCode')->middleware('permission:items.create');
    Route::post('/', 'store')->middleware('permission:items.create');
    Route::get('{id}', 'show')->middleware('permission:items.view');
    Route::put('{id}', 'update')->middleware('permission:items.update');
    Route::patch('{id}/status', 'updateStatus')->middleware('permission:items.update|items.deactivate');
    Route::get('{id}/revisions', 'revisions')->middleware('permission:items.view');
    Route::get('{id}/uom-conversions', 'conversions')->middleware($read);
    Route::post('{id}/uom-conversions', 'storeConversion')->middleware('permission:items.update');
    Route::put('{id}/uom-conversions/{conversionId}', 'updateConversion')->middleware('permission:items.update');
    Route::delete('{id}/uom-conversions/{conversionId}', 'destroyConversion')->middleware('permission:items.update');
    Route::get('{id}/documents', 'documents')->middleware('permission:items.view');
    Route::post('{id}/documents', 'storeDocument')->middleware('permission:items.update');
});
Route::get('documents/{id}/download', [DocumentController::class, 'download'])->whereNumber('id');
Route::delete('documents/{id}', [DocumentController::class, 'destroy'])->whereNumber('id');

// Versioned engineering documents
$versioned = function (string $prefix, string $controller, string $module) {
    Route::controller($controller)->prefix($prefix)->whereNumber('id')->group(function () use ($module) {
        Route::get('/', 'index')->middleware("permission:{$module}.view");
        Route::post('/', 'store')->middleware("permission:{$module}.create");
        Route::get('{id}', 'show')->middleware("permission:{$module}.view");
        Route::put('{id}', 'update')->middleware("permission:{$module}.update");
        Route::delete('{id}', 'destroy')->middleware("permission:{$module}.update");
        Route::post('{id}/approve', 'approve')->middleware("permission:{$module}.approve");
        Route::post('{id}/revise', 'revise')->middleware("permission:{$module}.create");
        Route::patch('{id}/status', 'updateStatus')->middleware("permission:{$module}.approve");
    });
};
$versioned('boms', BomController::class, 'boms');
Route::get('boms/{id}/structure', [BomController::class, 'structure'])->whereNumber('id')->middleware('permission:boms.view');
Route::get('boms/{id}/export', [BomController::class, 'export'])->whereNumber('id')->middleware('permission:boms.export');
$versioned('product-routings', RoutingController::class, 'routings');
$versioned('qc-specifications', QcSpecificationController::class, 'qc-specifications');

// Excel / CSV import (entity create/update permissions checked in the controller)
Route::controller(ImportController::class)->prefix('imports')->whereNumber('id')->middleware('permission:master-data.import')->group(function () {
    Route::get('types', 'types');
    Route::get('/', 'index');
    Route::get('templates/{entity}', 'template');
    Route::post('/', 'store');
    Route::get('{id}', 'show');
    Route::post('{id}/validate', 'validateBatch');
    Route::post('{id}/commit', 'commit');
    Route::get('{id}/errors', 'errors');
});
