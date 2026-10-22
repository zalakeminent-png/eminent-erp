<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Models\Factory;
use App\Models\Stock\Sku;
use App\Models\Stock\StockLedgerEntry;
use App\Models\Stock\StockLevel;
use App\Models\WarehouseLocation;
use App\Services\Stock\StockOverview;
use App\Services\Structure\StructureScope;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockOverviewController extends Controller
{
    private const FILTERS = ['search', 'factory_id', 'location_id', 'category', 'material', 'status', 'sort', 'direction', 'include_inactive'];

    public function __construct(
        private readonly StockOverview $overview,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $query = $this->overview->query($request->only(self::FILTERS), $request->user());

        if ($request->query('export')) {
            abort_unless($request->user()->can('stock-overview.export'), 403);

            return Csv::download('live-stock', ['SKU', 'Product Name', 'Category', 'Material', 'Size', 'Opening Stock', 'Total Inward', 'Total Rejected',
                'Total Outward', 'Live Stock Balance', 'Reorder Level', 'Status'],
                $query->cursor()->map(function ($r) {
                    $row = $this->overview->row($r);

                    return [$row['sku'], $row['product_name'], $row['category'], $row['material'], $row['size'], $row['opening_stock'], $row['total_inward'],
                        $row['total_rejected'], $row['total_outward'], $row['live_balance'], $row['reorder_level'] ?? '', $row['status_label']];
                }));
        }

        $page = $query->paginate(min(200, $request->integer('per_page', 50) ?: 50))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => $this->overview->row($r)),
            'meta' => SkuController::meta($page),
        ]);
    }

    /** Posted movements of one SKU (optionally one factory/location), newest first. */
    public function history(Request $request, Sku $sku): JsonResponse
    {
        abort_unless($sku->company_id === Company::current()?->id, 404);
        $query = StockLedgerEntry::query()->from('stock_ledger_entries as e')->select('e.*')->with(['document.vendor', 'document.department', 'location', 'creator'])
            ->where('e.sku_id', $sku->id);
        $this->overview->applyLocationFilters($query->getQuery(), $request->only(['factory_id', 'location_id']), $request->user(), 'e');
        $page = $query->orderByDesc('e.posted_at')->orderByDesc('e.id')->paginate(min(100, $request->integer('per_page', 25) ?: 25));

        return response()->json([
            'sku' => SkuController::rowFor($sku),
            'data' => collect($page->items())->map(fn (StockLedgerEntry $e) => [
                'id' => $e->id,
                'posted_at' => $e->posted_at?->toIso8601String(),
                'document' => ['id' => $e->document->id, 'type' => $e->document->type, 'number' => $e->document->number,
                    'date' => $e->document->document_date?->toDateString(), 'status' => $e->document->status],
                'is_reversal' => $e->is_reversal,
                'kind' => $e->kind,
                'location' => ['id' => $e->location->id, 'code' => $e->location->code, 'name' => $e->location->name],
                'qty_in' => StockOverview::number($e->qty_in),
                'qty_rejected' => StockOverview::number($e->qty_rejected),
                'qty_out' => StockOverview::number($e->qty_out),
                'balance_after' => StockOverview::number($e->balance_after),
                'party' => $e->document->vendor?->name ?? $e->document->department?->name ?? $e->document->recipient,
                'reference' => $e->document->reference,
                'user' => $e->creator?->full_name,
            ]),
            'meta' => SkuController::meta($page),
        ]);
    }

    /** Current balance of a SKU at a storage location (outward form). */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['sku_id' => ['required', 'integer'], 'location_id' => ['required', 'integer']]);
        $location = WarehouseLocation::query()->with('warehouse')->findOrFail($data['location_id']);
        $this->scope->ensureFactory($request->user(), $location->warehouse->factory_id);
        $level = StockLevel::query()->where('sku_id', $data['sku_id'])->where('location_id', $location->id)->value('quantity');

        return response()->json(['data' => ['available' => StockOverview::number($level ?? 0)]]);
    }

    /** Dropdown sources for stock screens, limited to the user's factories. */
    public function lookups(Request $request): JsonResponse
    {
        $user = $request->user();
        $factories = $this->scope->applyToFactories(Factory::query(), $user)->orderBy('name')->get(['id', 'code', 'name', 'is_active']);
        $locations = $this->scope->applyToLocations(WarehouseLocation::query()->with('warehouse:id,factory_id,is_active'), $user)
            ->orderBy('code')->get()->map(fn (WarehouseLocation $l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'factory_id' => $l->warehouse->factory_id, 'parent_id' => $l->parent_id,
                'is_active' => $l->is_active && $l->warehouse->is_active,
            ]);
        $departments = $this->scope->applyByFactory(Department::query(), $user)->orderBy('name')->get(['id', 'factory_id', 'code', 'name', 'is_active']);
        $distinct = fn (string $column) => Sku::query()->forCurrentCompany()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column);

        return response()->json(['data' => [
            'factories' => $factories,
            'locations' => $locations,
            'departments' => $departments,
            'categories' => $distinct('category'),
            'materials' => $distinct('material'),
            'units' => $distinct('unit'),
        ]]);
    }
}
