<?php

use App\Http\Controllers\Api\V1\Stock\SkuController;
use App\Http\Controllers\Api\V1\Stock\StockDocumentController;
use App\Http\Controllers\Api\V1\Stock\StockOverviewController;
use App\Http\Controllers\Api\V1\Stock\VendorController;
use App\Http\Controllers\Api\V1\Stock\VendorSkuController;
use App\Models\Stock\StockDocument;
use Illuminate\Support\Facades\Route;

/*
| Stock management: Stock Overview, SKU Master, Vendor Master (+ mapping),
| Inward Material, Outward Material and Opening Stock. Included inside the
| authenticated /api/v1 group; every route requires its own permission.
*/

$anyStock = 'permission:stock-overview.view|skus.view|vendors.view|inward.view|outward.view|opening-stock.view';

Route::controller(StockOverviewController::class)->prefix('stock')->group(function () use ($anyStock) {
    Route::get('overview', 'index')->middleware('permission:stock-overview.view');
    Route::get('history/{sku}', 'history')->middleware('permission:stock-overview.view|inward.view|outward.view');
    Route::get('availability', 'availability')->middleware('permission:stock-overview.view|outward.create|outward.update|outward.post');
    Route::get('lookups', 'lookups')->middleware($anyStock);
});

Route::controller(SkuController::class)->prefix('skus')->group(function () use ($anyStock) {
    Route::get('options', 'options')->middleware($anyStock.'|vendor-skus.view');
    Route::get('template', 'template')->middleware('permission:skus.import');
    Route::post('import', 'import')->middleware('permission:skus.import');
    Route::get('/', 'index')->middleware('permission:skus.view');
    Route::post('/', 'store')->middleware('permission:skus.create');
    Route::get('{sku}', 'show')->middleware('permission:skus.view');
    Route::put('{sku}', 'update')->middleware('permission:skus.update');
    Route::patch('{sku}/status', 'updateStatus')->middleware('permission:skus.update|skus.deactivate');
    Route::delete('{sku}', 'destroy')->middleware('permission:skus.delete');
});

Route::controller(VendorController::class)->prefix('vendors')->group(function () {
    Route::get('options', 'options')->middleware('permission:vendors.view|vendor-skus.view|inward.view|outward.view');
    Route::get('template', 'template')->middleware('permission:vendors.import');
    Route::post('import', 'import')->middleware('permission:vendors.import');
    Route::get('/', 'index')->middleware('permission:vendors.view');
    Route::post('/', 'store')->middleware('permission:vendors.create');
    Route::get('{vendor}', 'show')->middleware('permission:vendors.view');
    Route::put('{vendor}', 'update')->middleware('permission:vendors.update');
    Route::patch('{vendor}/status', 'updateStatus')->middleware('permission:vendors.update|vendors.deactivate');
    Route::delete('{vendor}', 'destroy')->middleware('permission:vendors.delete');
});

Route::controller(VendorSkuController::class)->prefix('vendor-skus')->group(function () {
    Route::get('template', 'template')->middleware('permission:vendor-skus.import');
    Route::post('import', 'import')->middleware('permission:vendor-skus.import');
    Route::get('/', 'index')->middleware('permission:vendor-skus.view');
    Route::post('/', 'store')->middleware('permission:vendor-skus.create');
    Route::put('{mapping}', 'update')->middleware('permission:vendor-skus.update');
    Route::delete('{mapping}', 'destroy')->middleware('permission:vendor-skus.delete');
});

foreach (['inward' => [StockDocument::INWARD, 'inward'], 'outward' => [StockDocument::OUTWARD, 'outward'], 'opening-stock' => [StockDocument::OPENING, 'opening-stock']] as $prefix => [$type, $module]) {
    Route::controller(StockDocumentController::class)->prefix($prefix)->whereNumber('id')->group(function () use ($type, $module) {
        Route::get('/', 'index')->defaults('type', $type)->middleware("permission:{$module}.view");
        Route::post('/', 'store')->defaults('type', $type)->middleware("permission:{$module}.create");
        Route::get('{id}', 'show')->defaults('type', $type)->middleware("permission:{$module}.view");
        Route::put('{id}', 'update')->defaults('type', $type)->middleware("permission:{$module}.update|{$module}.edit-posted");
        Route::delete('{id}', 'destroy')->defaults('type', $type)->middleware("permission:{$module}.delete");
        Route::post('{id}/post', 'post')->defaults('type', $type)->middleware("permission:{$module}.post");
        Route::post('{id}/reverse', 'reverse')->defaults('type', $type)->middleware("permission:{$module}.reverse");
        Route::post('{id}/cancel', 'cancel')->defaults('type', $type)->middleware("permission:{$module}.update");
    });
}
