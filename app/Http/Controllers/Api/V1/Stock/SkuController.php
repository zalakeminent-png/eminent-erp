<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Api\V1\Structure\ChangesStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Stock\Sku;
use App\Models\Stock\StockDocument;
use App\Models\Stock\VendorSku;
use App\Services\ActivityLogger;
use App\Services\Stock\StockOverview;
use App\Support\Csv;
use App\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SkuController extends Controller
{
    use ChangesStatus;

    private const SORTABLE = ['sku', 'product_name', 'category', 'material', 'size', 'created_at'];

    public const IMPORT_COLUMNS = [
        'sku' => ['sku', 'sku code', 'code'],
        'product_name' => ['product name', 'name', 'product'],
        'category' => ['category'],
        'material' => ['material', 'grade'],
        'size' => ['size'],
        'unit' => ['unit', 'uom'],
        'reorder_level' => ['reorder level', 'reorder'],
    ];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $query = Sku::query()->forCurrentCompany()->withCount(['mappings as vendors_count' => fn ($q) => $q->where('is_active', true)]);
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(sku) LIKE ?', [$like])->orWhereRaw('LOWER(product_name) LIKE ?', [$like]));
        }
        foreach (['category', 'material'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->query('status') === 'active');
        }
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'sku';
        $query->orderBy($sort, $request->query('direction') === 'desc' ? 'desc' : 'asc')->orderBy('id');

        if ($request->query('export')) {
            abort_unless($request->user()->can('skus.export'), 403);

            return Csv::download('sku-master', ['SKU', 'Product Name', 'Category', 'Material', 'Size', 'Unit', 'Reorder Level', 'Status'],
                $query->cursor()->map(fn (Sku $s) => [$s->sku, $s->product_name, $s->category, $s->material, $s->size, $s->unit,
                    $s->reorder_level === null ? '' : StockOverview::number($s->reorder_level), $s->is_active ? 'Active' : 'Inactive']));
        }

        $page = $query->paginate(min(100, $request->integer('per_page', 25) ?: 25))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Sku $s) => $this->row($s)),
            'meta' => self::meta($page),
            'filters' => [
                'categories' => Sku::query()->forCurrentCompany()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
                'materials' => Sku::query()->forCurrentCompany()->whereNotNull('material')->distinct()->orderBy('material')->pluck('material'),
            ],
        ]);
    }

    /** Active SKUs for pickers; marks whether each is mapped to the given vendor. */
    public function options(Request $request): JsonResponse
    {
        $query = Sku::query()->forCurrentCompany()->where('is_active', true)->orderBy('sku');
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(sku) LIKE ?', [$like])->orWhereRaw('LOWER(product_name) LIKE ?', [$like]));
        }
        $vendorId = $request->integer('vendor_id') ?: null;
        $mapped = $vendorId ? VendorSku::query()->where('vendor_id', $vendorId)->where('is_active', true)->pluck('sku_id')->flip() : collect();
        if ($vendorId && $request->boolean('mapped_only')) {
            $query->whereIn('id', $mapped->keys());
        }

        return response()->json(['data' => $query->limit(30)->get()->map(fn (Sku $s) => $this->row($s) + ['mapped' => $vendorId ? $mapped->has($s->id) : null])]);
    }

    public function show(Request $request, Sku $sku): JsonResponse
    {
        $this->ensureCompany($sku);
        $mappings = $sku->mappings()->with('vendor')->get()->map(fn (VendorSku $m) => [
            'id' => $m->id, 'is_active' => $m->is_active, 'remarks' => $m->remarks,
            'vendor' => ['id' => $m->vendor->id, 'code' => $m->vendor->code, 'name' => $m->vendor->name, 'is_active' => $m->vendor->is_active],
        ]);

        return response()->json(['data' => $this->row($sku) + ['vendors' => $mappings]]);
    }

    public function store(Request $request): JsonResponse
    {
        $sku = Sku::create($this->validated($request));
        $this->activity->log('sku.created', $sku, ['sku' => $sku->sku], "Created SKU {$sku->sku}");

        return response()->json(['data' => $this->row($sku->refresh()), 'message' => "SKU {$sku->sku} created."], 201);
    }

    public function update(Request $request, Sku $sku): JsonResponse
    {
        $this->ensureCompany($sku);
        $data = $this->validated($request, $sku);
        $before = $sku->only(array_keys($data));
        $sku->update($data);
        if ($changes = $this->activity->diff($before, $sku->only(array_keys($data)))) {
            $this->activity->log('sku.updated', $sku, ['changes' => $changes], "Updated SKU {$sku->sku}");
        }

        return response()->json(['data' => $this->row($sku->refresh()), 'message' => 'SKU saved.']);
    }

    public function updateStatus(Request $request, Sku $sku): JsonResponse
    {
        $this->ensureCompany($sku);
        $this->changeStatus($request, $sku, 'skus', 'sku');

        return response()->json(['data' => $this->row($sku->refresh()), 'message' => $sku->is_active ? 'SKU activated.' : 'SKU deactivated. Its history is kept.']);
    }

    /**
     * Permanently deletes a SKU and its vendor mappings. Refused while stock
     * entries (including deleted ones kept for stock history) refer to it.
     */
    public function destroy(Sku $sku): JsonResponse
    {
        $this->ensureCompany($sku);
        $entries = StockDocument::withTrashed()->where('sku_id', $sku->id);
        if ($count = (clone $entries)->count()) {
            $numbers = $entries->orderBy('number')->limit(5)->pluck('number')->implode(', ');

            return response()->json(['message' => "SKU {$sku->sku} cannot be deleted because {$count} stock entr".($count === 1 ? 'y uses' : 'ies use')." it ({$numbers}".($count > 5 ? ', …' : '').'). Stock history must stay complete — deactivate the SKU instead.'], 409);
        }
        $snapshot = $sku->only(['id', 'sku', 'product_name', 'category', 'material', 'size', 'unit', 'reorder_level']);
        DB::transaction(function () use ($sku) {
            $sku->mappings()->delete();
            $sku->delete();
        });
        $this->activity->log('sku.deleted', null, $snapshot, "Deleted SKU {$snapshot['sku']}");

        return response()->json(['message' => "SKU {$snapshot['sku']} deleted."]);
    }

    public function template(): StreamedResponse
    {
        return Csv::download('sku-import-template', ['SKU', 'Product Name', 'Category', 'Material', 'Size', 'Unit', 'Reorder Level'], []);
    }

    /**
     * CSV import. With dry_run=1 only validates; otherwise valid rows are
     * created in one transaction. Existing SKU codes are skipped, never changed.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,txt'], 'dry_run' => ['sometimes', 'boolean']],
            ['file.extensions' => 'Upload a .csv file (in Excel: File → Save As → CSV).']);
        $rows = Csv::read($request->file('file'), self::IMPORT_COLUMNS, ['sku', 'product_name']);
        $existing = Sku::query()->forCurrentCompany()->pluck('sku')->map(fn ($s) => mb_strtolower($s))->flip();
        $counts = collect($rows)->countBy(fn ($r) => mb_strtolower($r['values']['sku']));

        $results = [];
        foreach ($rows as ['row' => $number, 'values' => $v]) {
            $errors = [];
            if ($v['sku'] === '') {
                $errors[] = 'SKU is required.';
            } elseif (mb_strlen($v['sku']) > 60) {
                $errors[] = 'SKU is longer than 60 characters.';
            } elseif ($counts[mb_strtolower($v['sku'])] > 1) {
                $errors[] = 'This SKU appears more than once in the file.';
            }
            if ($v['product_name'] === '') {
                $errors[] = 'Product name is required.';
            }
            if ($v['reorder_level'] !== '' && ! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $v['reorder_level'])) {
                $errors[] = 'Reorder level must be a number.';
            }
            $status = $errors ? 'failed' : ($existing->has(mb_strtolower($v['sku'])) ? 'skipped' : 'ok');
            $results[] = ['row' => $number, 'sku' => $v['sku'], 'status' => $status,
                'message' => $errors ? implode(' ', $errors) : ($status === 'skipped' ? 'SKU already exists — not changed.' : 'Ready to import.'), 'values' => $v];
        }

        $created = 0;
        if (! $request->boolean('dry_run')) {
            DB::transaction(function () use ($results, &$created) {
                foreach ($results as $r) {
                    if ($r['status'] !== 'ok') {
                        continue;
                    }
                    $v = $r['values'];
                    Sku::create([
                        'sku' => $v['sku'], 'product_name' => $v['product_name'], 'category' => $v['category'] ?: null, 'material' => $v['material'] ?: null,
                        'size' => $v['size'] ?: null, 'unit' => $v['unit'] ?: null, 'reorder_level' => $v['reorder_level'] === '' ? null : $v['reorder_level'], 'is_active' => true,
                    ]);
                    $created++;
                }
            });
            $this->activity->log('sku.imported', null, ['created' => $created], "Imported {$created} SKU(s)");
        }

        return response()->json(self::importSummary($results, $created, $request->boolean('dry_run')));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Sku $sku = null): array
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', Rule::unique('skus', 'sku')->where('company_id', Company::current()?->id)->ignore($sku?->id)],
            'product_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'material' => ['nullable', 'string', 'max:80'],
            'size' => ['nullable', 'string', 'max:80'],
            'unit' => ['nullable', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
            'is_active' => [$sku ? 'prohibited' : 'sometimes', 'boolean'],
        ], [
            'sku.unique' => 'This SKU code already exists.',
            'reorder_level.regex' => 'Enter a number (up to 3 decimals), or leave empty.',
            'is_active.prohibited' => 'Use Activate/Deactivate to change the status.',
        ]);
        foreach (['category', 'material', 'size', 'unit'] as $f) {
            $data[$f] = isset($data[$f]) && trim($data[$f]) !== '' ? trim($data[$f]) : null;
        }
        $data['sku'] = trim($data['sku']);
        $data['reorder_level'] = ($data['reorder_level'] ?? '') === '' ? null : $data['reorder_level'];

        return $data;
    }

    private function ensureCompany(Sku $sku): void
    {
        abort_unless($sku->company_id === Company::current()?->id, 404);
    }

    /** @return array<string, mixed> */
    public static function rowFor(Sku $s): array
    {
        return [
            'id' => $s->id, 'sku' => $s->sku, 'product_name' => $s->product_name, 'category' => $s->category, 'material' => $s->material,
            'size' => $s->size, 'unit' => $s->unit, 'reorder_level' => $s->reorder_level === null ? null : StockOverview::number($s->reorder_level),
            'is_active' => $s->is_active, 'vendors_count' => $s->vendors_count ?? null, 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    private function row(Sku $s): array
    {
        return self::rowFor($s);
    }

    /** @param LengthAwarePaginator $page */
    public static function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }

    /** @param list<array<string, mixed>> $results */
    public static function importSummary(array $results, int $created, bool $dryRun): array
    {
        $count = fn ($s) => count(array_filter($results, fn ($r) => $r['status'] === $s));

        return [
            'dry_run' => $dryRun,
            'summary' => ['total' => count($results), 'ready' => $count('ok'), 'created' => $created, 'skipped' => $count('skipped'), 'failed' => $count('failed')],
            'rows' => array_map(fn ($r) => array_diff_key($r, ['values' => true]), $results),
            'message' => $dryRun
                ? "{$count('ok')} row(s) ready to import, {$count('skipped')} skipped, {$count('failed')} with errors."
                : "{$created} imported, {$count('skipped')} skipped, {$count('failed')} failed.",
        ];
    }
}
