<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\InventoryTransaction;
use App\Models\Inventory\LedgerEntry;
use App\Services\Inventory\StockBrowser;
use App\Services\Structure\StructureScope;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Read-only stock ledger with filters, running balances and export. */
class LedgerController extends Controller
{
    private const FILTERS = [
        'search', 'factory_id', 'warehouse_id', 'location_id', 'item_id', 'category_id', 'item_type', 'status', 'transaction_type',
        'department_id', 'user_id', 'date_from', 'date_to', 'source_document_type', 'source_document_id', 'order', 'direction_filter',
    ];

    public function __construct(private readonly StockBrowser $browser) {}

    public function index(Request $request): JsonResponse|BinaryFileResponse
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $query = $this->browser->ledger($request->only(self::FILTERS), $request->user());

        if ($format = $request->query('export')) {
            abort_unless($request->user()->can('inventory.reports.export'), 403);

            return Spreadsheet::download('stock-ledger', [
                'Posted At', 'Transaction', 'Type', 'Item Code', 'Item Name', 'Warehouse', 'Location', 'Status', 'Qty In', 'Qty Out', 'UOM',
                'Running Balance', 'Unit Cost', 'Value Change', 'Reference', 'Created By',
            ], $query->cursor()->map(function (LedgerEntry $e) {
                $row = $this->browser->ledgerRow($e);

                return [
                    $e->posted_at?->toDateTimeString(), $row['transaction_number'], $row['transaction_type'], $e->item?->code, $e->item?->name,
                    $row['warehouse']['code'] ?? null, $row['location']['code'] ?? null, $e->stock_status, $row['quantity_in'], $row['quantity_out'],
                    $row['uom']['code'] ?? null, $row['running_balance'], $row['unit_cost'], $row['value_delta'],
                    trim(($e->source_document_type ?? '').' '.($e->source_document_id ?? '')), $row['created_by']['full_name'] ?? null,
                ];
            }), $format === 'csv' ? 'csv' : 'xlsx');
        }

        $page = $query->paginate(min(200, $request->integer('per_page', 50) ?: 50))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (LedgerEntry $e) => $this->browser->ledgerRow($e))->values(),
            'meta' => InventoryRefs::meta($page),
        ]);
    }

    /** One transaction with all its entries (audit view). */
    public function transaction(Request $request, int $id, StructureScope $scope): JsonResponse
    {
        $transaction = InventoryTransaction::query()->with(['entries.item', 'entries.baseUom', 'entries.location.warehouse', 'entries.creator', 'creator', 'reversalOf', 'reversedBy'])->findOrFail($id);
        foreach ($transaction->entries->pluck('factory_id')->unique() as $factoryId) {
            $scope->ensureFactory($request->user(), $factoryId);
        }

        return response()->json(['data' => [
            'id' => $transaction->id,
            'transaction_number' => $transaction->transaction_number,
            'transaction_type' => $transaction->transaction_type,
            'source_document_type' => $transaction->source_document_type,
            'source_document_id' => $transaction->source_document_id,
            'remarks' => $transaction->remarks,
            'posted_at' => $transaction->posted_at?->toIso8601String(),
            'created_by' => Refs::user($transaction->creator),
            'reversal_of' => $transaction->reversalOf?->only(['id', 'transaction_number']),
            'reversed_by' => $transaction->reversedBy?->only(['id', 'transaction_number']),
            'entries' => $transaction->entries->map(fn (LedgerEntry $e) => $this->browser->ledgerRow($e->setRelation('transaction', $transaction))),
        ]]);
    }
}
