<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Api\V1\Structure\ChangesStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Stock\StockDocument;
use App\Models\Stock\Vendor;
use App\Models\Stock\VendorSku;
use App\Services\ActivityLogger;
use App\Services\Stock\DocumentNumber;
use App\Services\Stock\StockOverview;
use App\Support\Csv;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorController extends Controller
{
    use ChangesStatus;

    private const SORTABLE = ['code', 'name', 'city', 'contact_person', 'created_at'];

    public const IMPORT_COLUMNS = [
        'name' => ['vendor name', 'vendor', 'name'],
        'contact_person' => ['contact person', 'contact'],
        'phone' => ['phone', 'phone number', 'mobile'],
        'email' => ['email'],
        'city' => ['city'],
        'address' => ['address'],
        'notes' => ['notes', 'contact / city / notes', 'remarks'],
    ];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly DocumentNumber $numbers,
    ) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $query = Vendor::query()->forCurrentCompany()->withCount(['mappings as skus_count' => fn ($q) => $q->where('is_active', true)]);
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(contact_person, \'\')) LIKE ?', [$like])->orWhereRaw('LOWER(COALESCE(city, \'\')) LIKE ?', [$like]));
        }
        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->query('status') === 'active');
        }
        if ($request->filled('sku_id')) {
            $query->whereHas('mappings', fn ($q) => $q->where('sku_id', $request->integer('sku_id'))->where('is_active', true));
        }
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'name';
        $query->orderBy($sort, $request->query('direction') === 'desc' ? 'desc' : 'asc')->orderBy('id');

        if ($request->query('export')) {
            abort_unless($request->user()->can('vendors.export'), 403);

            return Csv::download('vendor-master', ['Vendor Code', 'Vendor Name', 'Contact Person', 'Phone', 'Email', 'City', 'Address', 'Notes', 'SKUs Supplied', 'Status'],
                $query->cursor()->map(fn (Vendor $v) => [$v->code, $v->name, $v->contact_person, $v->phone, $v->email, $v->city, $v->address, $v->notes, $v->skus_count, $v->is_active ? 'Active' : 'Inactive']));
        }

        $page = $query->paginate(min(100, $request->integer('per_page', 25) ?: 25))->withQueryString();

        return response()->json(['data' => collect($page->items())->map(fn (Vendor $v) => $this->row($v)), 'meta' => SkuController::meta($page)]);
    }

    /** Active vendors for pickers; marks whether each supplies the given SKU. */
    public function options(Request $request): JsonResponse
    {
        $query = Vendor::query()->forCurrentCompany()->where('is_active', true)->orderBy('name');
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]));
        }
        $skuId = $request->integer('sku_id') ?: null;
        $mapped = $skuId ? VendorSku::query()->where('sku_id', $skuId)->where('is_active', true)->pluck('vendor_id')->flip() : collect();

        return response()->json(['data' => $query->limit(30)->get()->map(fn (Vendor $v) => $this->row($v) + ['mapped' => $skuId ? $mapped->has($v->id) : null])]);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $this->ensureCompany($vendor);
        $vendor->loadCount(['mappings as skus_count' => fn ($q) => $q->where('is_active', true)]);
        $recent = StockDocument::query()->with(['sku', 'location'])->where('vendor_id', $vendor->id)->where('type', StockDocument::INWARD)
            ->where('status', StockDocument::POSTED)->latest('document_date')->latest('id')->limit(10)->get();

        return response()->json(['data' => $this->row($vendor) + [
            'mappings' => $vendor->mappings()->with('sku')->get()->sortBy(fn ($m) => $m->sku->sku)->values()->map(fn (VendorSku $m) => VendorSkuController::rowFor($m)),
            'inward_summary' => [
                'posted_entries' => StockDocument::query()->where('vendor_id', $vendor->id)->where('type', StockDocument::INWARD)->where('status', StockDocument::POSTED)->count(),
                'recent' => $recent->map(fn (StockDocument $d) => [
                    'id' => $d->id, 'number' => $d->number, 'date' => $d->document_date->toDateString(), 'sku' => $d->sku->sku,
                    'product_name' => $d->sku->product_name, 'received_qty' => StockOverview::number($d->received_qty),
                    'rejected_qty' => StockOverview::number($d->rejected_qty), 'location' => $d->location->code,
                ]),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $vendor = DB::transaction(function () use ($data) {
            $vendor = new Vendor($data);
            $vendor->code = $this->numbers->next('vendor', 'VEN-', 4);
            $vendor->save();

            return $vendor;
        });
        $this->activity->log('vendor.created', $vendor, ['code' => $vendor->code, 'name' => $vendor->name], "Created vendor {$vendor->name}");

        return response()->json(['data' => $this->row($vendor->refresh()), 'message' => "Vendor {$vendor->name} created ({$vendor->code})."], 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $this->ensureCompany($vendor);
        $data = $this->validated($request, $vendor);
        $before = $vendor->only(array_keys($data));
        $vendor->update($data);
        if ($changes = $this->activity->diff($before, $vendor->only(array_keys($data)))) {
            $this->activity->log('vendor.updated', $vendor, ['changes' => $changes], "Updated vendor {$vendor->name}");
        }

        return response()->json(['data' => $this->row($vendor->refresh()), 'message' => 'Vendor saved.']);
    }

    public function updateStatus(Request $request, Vendor $vendor): JsonResponse
    {
        $this->ensureCompany($vendor);
        $this->changeStatus($request, $vendor, 'vendors', 'vendor');

        return response()->json(['data' => $this->row($vendor->refresh()), 'message' => $vendor->is_active ? 'Vendor activated.' : 'Vendor deactivated. Its history is kept.']);
    }

    /** Permanently deletes a vendor and its SKU mappings, unless stock entries refer to it. */
    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->ensureCompany($vendor);
        $entries = StockDocument::withTrashed()->where('vendor_id', $vendor->id);
        if ($count = (clone $entries)->count()) {
            $numbers = $entries->orderBy('number')->limit(5)->pluck('number')->implode(', ');

            return response()->json(['message' => "{$vendor->name} cannot be deleted because {$count} stock entr".($count === 1 ? 'y uses' : 'ies use')." this vendor ({$numbers}".($count > 5 ? ', …' : '').'). Deactivate the vendor instead.'], 409);
        }
        $snapshot = $vendor->only(['id', 'code', 'name', 'contact_person', 'phone', 'email', 'city']);
        DB::transaction(function () use ($vendor) {
            $vendor->mappings()->delete();
            $vendor->delete();
        });
        $this->activity->log('vendor.deleted', null, $snapshot, "Deleted vendor {$snapshot['name']}");

        return response()->json(['message' => "Vendor {$snapshot['name']} deleted."]);
    }

    public function template(): StreamedResponse
    {
        return Csv::download('vendor-import-template', ['Vendor Name', 'Contact Person', 'Phone', 'Email', 'City', 'Address', 'Notes'], []);
    }

    /** CSV import; dry_run=1 validates only. Existing vendor names are skipped. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,txt'], 'dry_run' => ['sometimes', 'boolean']],
            ['file.extensions' => 'Upload a .csv file (in Excel: File → Save As → CSV).']);
        $rows = Csv::read($request->file('file'), self::IMPORT_COLUMNS, ['name']);
        $existing = Vendor::query()->forCurrentCompany()->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip();
        $counts = collect($rows)->countBy(fn ($r) => mb_strtolower($r['values']['name']));

        $results = [];
        foreach ($rows as ['row' => $number, 'values' => $v]) {
            $errors = [];
            if ($v['name'] === '') {
                $errors[] = 'Vendor name is required.';
            } elseif ($counts[mb_strtolower($v['name'])] > 1) {
                $errors[] = 'This vendor appears more than once in the file.';
            }
            if ($v['email'] !== '' && ! filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email is not valid.';
            }
            foreach (['name' => 150, 'contact_person' => 120, 'phone' => 40, 'city' => 80, 'address' => 500] as $key => $max) {
                if (mb_strlen($v[$key]) > $max) {
                    $errors[] = "{$key} is too long (max {$max}).";
                }
            }
            $status = $errors ? 'failed' : ($existing->has(mb_strtolower($v['name'])) ? 'skipped' : 'ok');
            $results[] = ['row' => $number, 'vendor' => $v['name'], 'status' => $status,
                'message' => $errors ? implode(' ', $errors) : ($status === 'skipped' ? 'Vendor already registered — not changed.' : 'Ready to import.'), 'values' => $v];
        }

        $created = 0;
        if (! $request->boolean('dry_run')) {
            DB::transaction(function () use ($results, &$created) {
                foreach ($results as $r) {
                    if ($r['status'] !== 'ok') {
                        continue;
                    }
                    $vendor = new Vendor(array_map(fn ($x) => $x === '' ? null : $x, $r['values']) + ['is_active' => true]);
                    $vendor->code = $this->numbers->next('vendor', 'VEN-', 4);
                    $vendor->save();
                    $created++;
                }
            });
            $this->activity->log('vendor.imported', null, ['created' => $created], "Imported {$created} vendor(s)");
        }

        return response()->json(SkuController::importSummary($results, $created, $request->boolean('dry_run')));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        $companyId = Company::current()?->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{5,40}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => [$vendor ? 'prohibited' : 'sometimes', 'boolean'],
        ], ['phone.regex' => 'Enter a valid phone number.', 'is_active.prohibited' => 'Use Activate/Deactivate to change the status.']);
        $data['name'] = trim($data['name']);

        // Registered once: names are unique regardless of case.
        $duplicate = Vendor::query()->where('company_id', $companyId)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->when($vendor, fn ($q) => $q->whereKeyNot($vendor->id))->first();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "A vendor named {$duplicate->name} is already registered ({$duplicate->code})."]);
        }

        return $data;
    }

    private function ensureCompany(Vendor $vendor): void
    {
        abort_unless($vendor->company_id === Company::current()?->id, 404);
    }

    /** @return array<string, mixed> */
    private function row(Vendor $v): array
    {
        return [
            'id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'contact_person' => $v->contact_person, 'phone' => $v->phone,
            'email' => $v->email, 'city' => $v->city, 'address' => $v->address, 'notes' => $v->notes, 'is_active' => $v->is_active,
            'skus_count' => $v->skus_count ?? null, 'created_at' => $v->created_at?->toIso8601String(),
        ];
    }
}
