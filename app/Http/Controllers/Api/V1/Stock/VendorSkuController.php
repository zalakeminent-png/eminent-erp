<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Stock\Sku;
use App\Models\Stock\Vendor;
use App\Models\Stock\VendorSku;
use App\Services\ActivityLogger;
use App\Support\Csv;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vendor ↔ SKU mapping (many-to-many). The product name always comes from
 * SKU Master. Past inward entries keep their own "vendor mapped" result,
 * so editing or removing a mapping never changes history.
 */
class VendorSkuController extends Controller
{
    public const IMPORT_COLUMNS = [
        'vendor' => ['vendor name', 'vendor'],
        'sku' => ['sku supplied', 'sku', 'sku code'],
    ];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse|StreamedResponse
    {
        $query = VendorSku::query()->with(['vendor', 'sku'])
            ->join('vendors', 'vendors.id', '=', 'vendor_skus.vendor_id')->join('skus', 'skus.id', '=', 'vendor_skus.sku_id')
            ->where('vendors.company_id', Company::current()?->id)
            ->select('vendor_skus.*');
        if ($request->filled('vendor_id')) {
            $query->where('vendor_skus.vendor_id', $request->integer('vendor_id'));
        }
        if ($request->filled('sku_id')) {
            $query->where('vendor_skus.sku_id', $request->integer('sku_id'));
        }
        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('vendor_skus.is_active', $request->query('status') === 'active');
        }
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(vendors.name) LIKE ?', [$like])->orWhereRaw('LOWER(skus.sku) LIKE ?', [$like])
                ->orWhereRaw('LOWER(skus.product_name) LIKE ?', [$like]));
        }
        $query->orderBy('vendors.name')->orderBy('skus.sku');

        if ($request->query('export')) {
            abort_unless($request->user()->can('vendors.export'), 403);

            return Csv::download('vendor-sku-mapping', ['Vendor', 'Vendor Code', 'SKU Supplied', 'Product Name', 'Status'],
                $query->cursor()->map(fn (VendorSku $m) => [$m->vendor->name, $m->vendor->code, $m->sku->sku, $m->sku->product_name, $m->is_active ? 'Active' : 'Inactive']));
        }

        $page = $query->paginate(min(100, $request->integer('per_page', 25) ?: 25))->withQueryString();

        return response()->json(['data' => collect($page->items())->map(fn (VendorSku $m) => self::rowFor($m)), 'meta' => SkuController::meta($page)]);
    }

    /** Maps one vendor to one or more SKUs (duplicates are rejected). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            'sku_ids' => ['required', 'array', 'min:1', 'max:200'],
            'sku_ids.*' => ['integer', 'distinct'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ], ['sku_ids.required' => 'Select at least one SKU.']);

        $vendor = Vendor::query()->forCurrentCompany()->find($data['vendor_id']);
        if (! $vendor) {
            throw ValidationException::withMessages(['vendor_id' => 'Select a vendor.']);
        }
        if (! $vendor->is_active) {
            throw ValidationException::withMessages(['vendor_id' => "Vendor {$vendor->name} is inactive and cannot be given new SKUs."]);
        }
        $skus = Sku::query()->forCurrentCompany()->whereKey($data['sku_ids'])->get()->keyBy('id');
        $existing = VendorSku::query()->where('vendor_id', $vendor->id)->whereIn('sku_id', $data['sku_ids'])->get()->keyBy('sku_id');
        $errors = [];
        foreach ($data['sku_ids'] as $i => $skuId) {
            $sku = $skus->get($skuId);
            if (! $sku) {
                $errors["sku_ids.{$i}"] = 'Unknown SKU.';
            } elseif (! $sku->is_active) {
                $errors["sku_ids.{$i}"] = "SKU {$sku->sku} is inactive and cannot be mapped.";
            } elseif ($existing->has($skuId)) {
                $errors["sku_ids.{$i}"] = $existing[$skuId]->is_active
                    ? "{$vendor->name} is already mapped to {$sku->sku}."
                    : "{$vendor->name} already has an inactive mapping to {$sku->sku}; reactivate it instead.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $created = DB::transaction(fn () => collect($data['sku_ids'])->map(fn ($skuId) => VendorSku::create([
            'vendor_id' => $vendor->id, 'sku_id' => $skuId, 'is_active' => true, 'remarks' => $data['remarks'] ?? null,
        ])));
        $this->activity->log('vendor_sku.mapped', $vendor, ['skus' => $skus->pluck('sku')->values()->all()], "Mapped {$created->count()} SKU(s) to {$vendor->name}");

        return response()->json([
            'data' => $created->map(fn (VendorSku $m) => self::rowFor($m->load(['vendor', 'sku']))),
            'message' => "{$created->count()} SKU(s) mapped to {$vendor->name}.",
        ], 201);
    }

    /** Edit a mapping: activate/deactivate or change remarks. */
    public function update(Request $request, VendorSku $mapping): JsonResponse
    {
        $this->ensureCompany($mapping);
        $data = $request->validate(['is_active' => ['sometimes', 'boolean'], 'remarks' => ['nullable', 'string', 'max:255']]);
        if (($data['is_active'] ?? false) && ! $mapping->is_active && (! $mapping->vendor->is_active || ! $mapping->sku->is_active)) {
            throw ValidationException::withMessages(['is_active' => 'Activate the vendor and the SKU before reactivating this mapping.']);
        }
        $mapping->update($data);
        $this->activity->log('vendor_sku.updated', $mapping->vendor, ['sku' => $mapping->sku->sku, 'changes' => $data], "Updated mapping {$mapping->vendor->name} → {$mapping->sku->sku}");

        return response()->json(['data' => self::rowFor($mapping->refresh()->load(['vendor', 'sku'])), 'message' => 'Mapping saved.']);
    }

    /** Removes a mapping. Past inward entries keep their recorded vendor check. */
    public function destroy(VendorSku $mapping): JsonResponse
    {
        $this->ensureCompany($mapping);
        $label = "{$mapping->vendor->name} → {$mapping->sku->sku}";
        $vendor = $mapping->vendor;
        $mapping->delete();
        $this->activity->log('vendor_sku.removed', $vendor, ['mapping' => $label], "Removed mapping {$label}");

        return response()->json(['message' => "Mapping {$label} removed."]);
    }

    public function template(): StreamedResponse
    {
        return Csv::download('vendor-sku-mapping-template', ['Vendor Name', 'SKU Supplied'], []);
    }

    /**
     * CSV import of mappings. Vendors and SKUs must already exist and be
     * active — unknown SKUs are reported, never created.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,txt'], 'dry_run' => ['sometimes', 'boolean']],
            ['file.extensions' => 'Upload a .csv file (in Excel: File → Save As → CSV).']);
        $rows = Csv::read($request->file('file'), self::IMPORT_COLUMNS, ['vendor', 'sku']);
        $vendors = Vendor::query()->forCurrentCompany()->get()->keyBy(fn ($v) => mb_strtolower(trim($v->name)));
        $skus = Sku::query()->forCurrentCompany()->get()->keyBy(fn ($s) => mb_strtolower($s->sku));
        $existing = VendorSku::query()->get(['vendor_id', 'sku_id'])->map(fn ($m) => "{$m->vendor_id}|{$m->sku_id}")->flip();
        $seen = [];

        $results = [];
        foreach ($rows as ['row' => $number, 'values' => $v]) {
            $errors = [];
            $vendor = $vendors->get(mb_strtolower($v['vendor']));
            $sku = $skus->get(mb_strtolower($v['sku']));
            if ($v['vendor'] === '') {
                $errors[] = 'Vendor name is required.';
            } elseif (! $vendor) {
                $errors[] = "Vendor {$v['vendor']} is not registered in Vendor Master.";
            } elseif (! $vendor->is_active) {
                $errors[] = "Vendor {$vendor->name} is inactive.";
            }
            if ($v['sku'] === '') {
                $errors[] = 'SKU is required.';
            } elseif (! $sku) {
                $errors[] = "SKU {$v['sku']} does not exist in SKU Master.";
            } elseif (! $sku->is_active) {
                $errors[] = "SKU {$sku->sku} is inactive.";
            }
            $key = $vendor && $sku ? "{$vendor->id}|{$sku->id}" : null;
            if (! $errors && isset($seen[$key])) {
                $errors[] = 'This vendor and SKU appear more than once in the file.';
            }
            $status = $errors ? 'failed' : ($existing->has($key) ? 'skipped' : 'ok');
            if ($key) {
                $seen[$key] = true;
            }
            $results[] = ['row' => $number, 'vendor' => $v['vendor'], 'sku' => $v['sku'], 'status' => $status,
                'message' => $errors ? implode(' ', $errors) : ($status === 'skipped' ? 'Already mapped — not changed.' : 'Ready to import.'),
                'values' => ['vendor_id' => $vendor?->id, 'sku_id' => $sku?->id]];
        }

        $created = 0;
        if (! $request->boolean('dry_run')) {
            DB::transaction(function () use ($results, &$created) {
                foreach ($results as $r) {
                    if ($r['status'] === 'ok') {
                        VendorSku::create($r['values'] + ['is_active' => true]);
                        $created++;
                    }
                }
            });
            $this->activity->log('vendor_sku.imported', null, ['created' => $created], "Imported {$created} vendor-SKU mapping(s)");
        }

        return response()->json(SkuController::importSummary($results, $created, $request->boolean('dry_run')));
    }

    private function ensureCompany(VendorSku $mapping): void
    {
        abort_unless($mapping->vendor->company_id === Company::current()?->id, 404);
    }

    /** @return array<string, mixed> */
    public static function rowFor(VendorSku $m): array
    {
        return [
            'id' => $m->id, 'is_active' => $m->is_active, 'remarks' => $m->remarks,
            'vendor' => ['id' => $m->vendor->id, 'code' => $m->vendor->code, 'name' => $m->vendor->name, 'is_active' => $m->vendor->is_active],
            'sku' => ['id' => $m->sku->id, 'sku' => $m->sku->sku, 'product_name' => $m->sku->product_name, 'category' => $m->sku->category,
                'material' => $m->sku->material, 'is_active' => $m->sku->is_active],
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
