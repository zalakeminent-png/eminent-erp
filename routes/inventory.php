<?php

use App\Http\Controllers\Api\V1\Inventory\InventoryDashboardController;
use App\Http\Controllers\Api\V1\Inventory\InventoryDocumentController;
use App\Http\Controllers\Api\V1\Inventory\LedgerController;
use App\Http\Controllers\Api\V1\Inventory\LookupController;
use App\Http\Controllers\Api\V1\Inventory\OpeningStockController;
use App\Http\Controllers\Api\V1\Inventory\ReasonCodeController;
use App\Http\Controllers\Api\V1\Inventory\ReportController;
use App\Http\Controllers\Api\V1\Inventory\ReservationController;
use App\Http\Controllers\Api\V1\Inventory\StockController;
use App\Http\Controllers\Api\V1\Inventory\StockCountController;
use App\Models\Inventory\InventoryDocument;
use Illuminate\Support\Facades\Route;

/*
| Module 4 — Inventory & Warehouse. Included inside the authenticated
| /api/v1 group. Route middleware enforces the screen permission; the
| services re-check the specific action permission, factory scope and
| document state for every write.
*/

Route::prefix('inventory')->group(function () {
    Route::get('dashboard', InventoryDashboardController::class)->middleware('permission:inventory.dashboard.view');

    // Scoped dropdown sources for any inventory user.
    $anyInventory = 'permission:'.implode('|', [
        'inventory.dashboard.view', 'inventory.stock.view', 'inventory.opening-stock.view', 'inventory.grn.view', 'inventory.transfers.view',
        'inventory.material-issues.view', 'inventory.returns.view', 'inventory.wip.view', 'inventory.finished-goods.view',
        'inventory.qc-disposition.view', 'inventory.scrap.view', 'inventory.reservations.view', 'inventory.adjustments.view',
        'inventory.counts.view', 'inventory.ledger.view', 'inventory.reports.view', 'inventory.settings.manage',
    ]);
    Route::controller(LookupController::class)->prefix('lookups')->middleware($anyInventory)->group(function () {
        Route::get('structure', 'structure');
        Route::get('locations', 'locations');
        Route::get('suppliers', 'suppliers')->middleware('permission:inventory.grn.view|inventory.reports.view');
    });

    // Stock views (read-only, derived from posted balances)
    Route::controller(StockController::class)->group(function () {
        Route::get('stock', 'index')->defaults('preset', 'stock')->middleware('permission:inventory.stock.view');
        Route::get('stock/availability', 'availability')
            ->middleware('permission:inventory.stock.view|inventory.transfers.create|inventory.material-issues.create|inventory.adjustments.create|inventory.reservations.create|inventory.scrap.create');
        Route::get('stock/{itemId}', 'show')->whereNumber('itemId')->middleware('permission:inventory.stock.view');
        Route::get('wip', 'index')->defaults('preset', 'wip')->middleware('permission:inventory.wip.view');
        Route::get('finished-goods', 'index')->defaults('preset', 'finished-goods')->middleware('permission:inventory.finished-goods.view');
        Route::get('qc-stock', 'index')->defaults('preset', 'qc')->middleware('permission:inventory.qc-disposition.view');
        Route::get('scrap-stock', 'index')->defaults('preset', 'scrap')->middleware('permission:inventory.scrap.view');
        Route::put('{kind}/{id}/purpose', 'updatePurpose')->where('kind', 'warehouses|locations')->whereNumber('id')->middleware('permission:inventory.settings.manage');
    });

    Route::get('ledger', [LedgerController::class, 'index'])->middleware('permission:inventory.ledger.view');
    Route::get('transactions/{id}', [LedgerController::class, 'transaction'])->whereNumber('id')->middleware('permission:inventory.ledger.view');

    // Opening stock wizard
    Route::controller(OpeningStockController::class)->prefix('opening-stock')->whereNumber('id')->group(function () {
        Route::get('template', 'template')->middleware('permission:inventory.opening-stock.view');
        Route::post('import', 'upload')->middleware('permission:inventory.opening-stock.import');
        Route::get('imports', 'index')->middleware('permission:inventory.opening-stock.view');
        Route::get('imports/{id}', 'show')->middleware('permission:inventory.opening-stock.view');
        Route::get('imports/{id}/errors', 'errors')->middleware('permission:inventory.opening-stock.view');
        Route::post('imports/{id}/{action}', 'action')->where('action', 'validate|submit|approve|reject|post|reverse')
            ->middleware('permission:inventory.opening-stock.import|inventory.opening-stock.create|inventory.opening-stock.approve|inventory.opening-stock.post');
    });

    // Stock documents: one controller, the route supplies the type.
    $documents = [
        'grns' => [InventoryDocument::GRN, 'inventory.grn', 'inventory.grn.create|inventory.grn.update'],
        'transfers' => [InventoryDocument::TRANSFER, 'inventory.transfers', 'inventory.transfers.create'],
        'material-issues' => [InventoryDocument::MATERIAL_ISSUE, 'inventory.material-issues', 'inventory.material-issues.create'],
        'returns' => [InventoryDocument::MATERIAL_RETURN, 'inventory.returns', 'inventory.returns.create'],
        'adjustments' => [InventoryDocument::ADJUSTMENT, 'inventory.adjustments', 'inventory.adjustments.create'],
        'scrap' => [InventoryDocument::SCRAP, 'inventory.scrap', 'inventory.scrap.create'],
        'qc-dispositions' => [InventoryDocument::QC_DISPOSITION, 'inventory.qc-disposition', 'inventory.qc-disposition.release|inventory.qc-disposition.hold|inventory.qc-disposition.reject'],
    ];
    foreach ($documents as $slug => [$type, $module, $create]) {
        Route::controller(InventoryDocumentController::class)->prefix($slug)->whereNumber('id')->group(function () use ($type, $module, $create) {
            Route::get('/', 'index')->defaults('type', $type)->middleware("permission:{$module}.view");
            Route::post('/', 'store')->defaults('type', $type)->middleware("permission:{$create}");
            Route::get('{id}', 'show')->defaults('type', $type)->middleware("permission:{$module}.view");
            Route::put('{id}', 'update')->defaults('type', $type)->middleware("permission:{$create}");
            Route::post('{id}/attachments', 'storeAttachment')->defaults('type', $type)->middleware("permission:{$create}");
            // The action's own permission is checked by the document service.
            Route::post('{id}/{action}', 'action')->defaults('type', $type)->where('action', InventoryDocumentController::actionPattern())
                ->middleware("permission:{$module}.view");
        });
    }

    Route::controller(ReservationController::class)->prefix('reservations')->whereNumber('id')->group(function () {
        Route::get('/', 'index')->middleware('permission:inventory.reservations.view');
        Route::post('/', 'store')->middleware('permission:inventory.reservations.create');
        Route::get('{id}', 'show')->middleware('permission:inventory.reservations.view');
        Route::post('{id}/release', 'release')->middleware('permission:inventory.reservations.release');
    });

    Route::controller(StockCountController::class)->prefix('stock-counts')->whereNumber('id')->group(function () {
        Route::get('/', 'index')->middleware('permission:inventory.counts.view');
        Route::post('/', 'store')->middleware('permission:inventory.counts.create');
        Route::get('{id}', 'show')->middleware('permission:inventory.counts.view');
        Route::put('{id}/counts', 'record')->middleware('permission:inventory.counts.create|inventory.counts.submit');
        Route::post('{id}/{action}', 'action')->where('action', 'start|submit|approve|post|cancel')->middleware('permission:inventory.counts.view');
    });

    Route::get('reason-codes', [ReasonCodeController::class, 'index'])
        ->middleware('permission:inventory.settings.manage|inventory.scrap.view|inventory.adjustments.view|inventory.returns.view|inventory.qc-disposition.view');
    Route::post('reason-codes', [ReasonCodeController::class, 'store'])->middleware('permission:inventory.settings.manage');
    Route::put('reason-codes/{id}', [ReasonCodeController::class, 'update'])->whereNumber('id')->middleware('permission:inventory.settings.manage');

    Route::get('reports', [ReportController::class, 'index'])->middleware('permission:inventory.reports.view');
    Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('permission:inventory.reports.view');
});
