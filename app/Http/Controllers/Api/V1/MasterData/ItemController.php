<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ItemRequest;
use App\Http\Resources\MasterData\DocumentResource;
use App\Http\Resources\MasterData\ItemResource;
use App\Http\Resources\MasterData\ItemUomConversionResource;
use App\Models\Company;
use App\Models\MasterData\Bom;
use App\Models\MasterData\BomLine;
use App\Models\MasterData\Item;
use App\Models\MasterData\ItemCategory;
use App\Models\MasterData\ItemUomConversion;
use App\Models\MasterData\MasterDataDocument;
use App\Models\MasterData\MaterialGrade;
use App\Models\MasterData\UnitOfMeasure;
use App\Services\ActivityLogger;
use App\Services\MasterData\DocumentStorage;
use App\Services\MasterData\ItemService;
use App\Services\MasterData\ItemUsage;
use App\Services\MasterData\SkuGenerator;
use App\Services\MasterData\UomConverter;
use App\Support\Decimal;
use App\Support\Search;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ItemController extends Controller
{
    private const SORTABLE = ['code', 'name', 'item_type', 'revision', 'is_active', 'created_at', 'updated_at'];

    private const DETAIL_RELATIONS = [
        'category', 'materialGrade', 'baseUom', 'purchaseUom', 'productionUom', 'dimensionUom', 'weightUom',
        'defaultWarehouse', 'defaultLocation', 'attributeValues.attribute', 'uomConversions.uom', 'documents', 'creator',
    ];

    public function __construct(
        private readonly ItemService $items,
        private readonly ActivityLogger $activity,
        private readonly UomConverter $uoms,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $this->filtered($request)
            ->with(['category', 'materialGrade', 'baseUom', 'documents' => fn ($q) => $q->where('category', 'image')])
            ->paginate($request->integer('per_page', 15) ?: 15)
            ->withQueryString();

        return ItemResource::collection($items);
    }

    public function show(int $id, ItemUsage $usage): JsonResponse
    {
        $item = $this->find($id)->load(self::DETAIL_RELATIONS);

        return (new ItemResource($item))->additional([
            'meta' => [
                'usage' => $usage->counts($item),
                'locked_fields' => $usage->lockReasons($item) ? ItemService::LOCKED_WHEN_REFERENCED : [],
                'lock_reasons' => $usage->lockReasons($item),
            ],
        ])->response();
    }

    public function store(ItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $conversions = $data['uom_conversions'] ?? [];
        unset($data['uom_conversions'], $data['is_active']);
        $data['is_active'] = $request->boolean('is_active', true);

        $item = DB::transaction(function () use ($data, $conversions, $request) {
            $item = $this->items->save(null, $data, $request->input('revision_reason'));
            foreach ($conversions as $index => $conversion) {
                $this->saveConversion($item, $conversion, null, "uom_conversions.{$index}.factor");
            }
            $this->ensureUnitsConvertible($item);

            return $item;
        });

        return (new ItemResource($item->refresh()->load(self::DETAIL_RELATIONS)))
            ->additional(['message' => "Item {$item->code} created."])
            ->response()->setStatusCode(201);
    }

    public function update(ItemRequest $request, int $id): ItemResource
    {
        $item = $this->find($id);
        $data = collect($request->validated())->except(['is_active', 'uom_conversions'])->all();

        DB::transaction(function () use ($item, $data, $request) {
            $this->items->save($item, $data, $request->input('revision_reason'));
            $this->ensureUnitsConvertible($item->refresh());
        });

        return new ItemResource($item->refresh()->load(self::DETAIL_RELATIONS));
    }

    public function updateStatus(Request $request, int $id): ItemResource
    {
        $item = $this->find($id);
        $activate = (bool) $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        abort_unless($request->user()->can($activate ? 'items.update' : 'items.deactivate'), 403);

        if ($item->is_active !== $activate) {
            if ($activate && ! $item->category->is_active) {
                throw ValidationException::withMessages(['is_active' => 'Activate the item category first.']);
            }
            $item->forceFill(['is_active' => $activate])->save();
            $this->activity->log($activate ? 'item.activated' : 'item.deactivated', $item, ['code' => $item->code], ($activate ? 'Activated' : 'Deactivated')." item {$item->code}");
        }

        return new ItemResource($item->load(self::DETAIL_RELATIONS));
    }

    public function revisions(int $id): JsonResponse
    {
        $item = $this->find($id);

        return response()->json([
            'data' => $item->revisions()->with('author')->get()->map(fn ($r) => [
                'revision' => $r->revision,
                'changes' => $r->changes,
                'reason' => $r->reason,
                'changed_by' => $r->author?->full_name,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Next code preview for the form (does not reserve the number). */
    public function nextCode(Request $request, SkuGenerator $sku): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('item_categories', 'id')],
            'material_grade_id' => ['nullable', 'integer', Rule::exists('material_grades', 'id')],
        ]);
        $category = ItemCategory::findOrFail($data['category_id']);
        $prefix = ($category->resolvedSkuPrefix() ?? $category->code).(isset($data['material_grade_id']) ? '-'.MaterialGrade::find($data['material_grade_id'])->code : '');

        return response()->json(['data' => [
            'mode' => $sku->mode(),
            'pattern' => config('erp.master_data.sku.pattern'),
            'example_prefix' => $prefix,
            'suggested_item_type' => $category->resolvedItemType(),
        ]]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $query = $this->filtered($request)->with(['category', 'materialGrade', 'baseUom', 'purchaseUom', 'productionUom']);

        $rows = (function () use ($query) {
            foreach ($query->lazy(500) as $item) {
                yield [
                    $item->code, $item->name, $item->category?->code, $item->item_type, $item->materialGrade?->code, $item->baseUom?->code,
                    $item->purchaseUom?->code, $item->productionUom?->code, $item->drawing_number, $item->drawing_revision,
                    $item->internal_part_number, $item->customer_part_number, Decimal::normalize((string) ($item->weight_per_piece ?? '0')),
                    $item->is_stock_item ? 'Yes' : 'No', $item->requires_qc ? 'Yes' : 'No', $item->is_purchased ? 'Yes' : 'No',
                    $item->is_manufactured ? 'Yes' : 'No', $item->is_sold ? 'Yes' : 'No', $item->revision, $item->is_active ? 'Active' : 'Inactive',
                ];
            }
        })();

        $this->activity->log('item.exported', null, ['format' => $format, 'filters' => $request->only(['search', 'category_id', 'item_type', 'status'])], 'Exported items');

        return Spreadsheet::download('items-'.now()->format('Ymd-His'), [
            'Item Code', 'Item Name', 'Category', 'Item Type', 'Material Grade', 'Base UOM', 'Purchase UOM', 'Production UOM',
            'Drawing No.', 'Drawing Rev.', 'Internal Part No.', 'Customer Part No.', 'Weight / Piece', 'Stock Item',
            'Requires QC', 'Purchased', 'Manufactured', 'Sold', 'Revision', 'Status',
        ], $rows, $format);
    }

    // ---- Item-specific UOM conversions --------------------------------

    public function conversions(int $id): AnonymousResourceCollection
    {
        return ItemUomConversionResource::collection($this->find($id)->uomConversions()->with('uom')->get());
    }

    public function storeConversion(Request $request, int $id): JsonResponse
    {
        $item = $this->find($id);
        $data = $this->validateConversion($request, $item);
        $conversion = DB::transaction(fn () => $this->saveConversion($item, $data, null));

        return (new ItemUomConversionResource($conversion->load('uom')))->additional(['message' => 'Conversion added.'])->response()->setStatusCode(201);
    }

    public function updateConversion(Request $request, int $id, int $conversionId): ItemUomConversionResource
    {
        $item = $this->find($id);
        $conversion = $item->uomConversions()->findOrFail($conversionId);
        $data = $this->validateConversion($request, $item, $conversion);
        if ($this->conversionInApprovedUse($item, $conversion->uom_id) && ! Decimal::approximatelyEqual($data['factor'], (string) $conversion->factor)) {
            throw ValidationException::withMessages(['factor' => 'Approved BOMs rely on this conversion, so its factor cannot change.']);
        }
        DB::transaction(fn () => $this->saveConversion($item, $data, $conversion));

        return new ItemUomConversionResource($conversion->refresh()->load('uom'));
    }

    public function destroyConversion(int $id, int $conversionId): JsonResponse
    {
        $item = $this->find($id);
        $conversion = $item->uomConversions()->findOrFail($conversionId);

        if (in_array($conversion->uom_id, [$item->purchase_uom_id, $item->production_uom_id], true) || $this->conversionInApprovedUse($item, $conversion->uom_id)) {
            return response()->json(['message' => 'This conversion is used by the item\'s purchase/production unit or by approved BOMs. Deactivate it instead.'], 409);
        }

        $snapshot = ['item' => $item->code, 'uom_id' => $conversion->uom_id, 'factor' => (string) $conversion->factor];
        $conversion->delete();
        $this->activity->log('item.uom_conversion_deleted', $item, $snapshot, "Removed a unit conversion from {$item->code}");

        return response()->json(['message' => 'Conversion removed.']);
    }

    // ---- Documents ------------------------------------------------------

    public function documents(int $id): AnonymousResourceCollection
    {
        return DocumentResource::collection($this->find($id)->documents()->with('uploader')->get());
    }

    public function storeDocument(Request $request, int $id, DocumentStorage $storage): JsonResponse
    {
        $item = $this->find($id);
        $config = config('erp.master_data.documents');
        $isImage = $request->input('category') === 'image';

        $data = $request->validate([
            'category' => ['required', Rule::in(MasterDataDocument::CATEGORIES)],
            'title' => ['nullable', 'string', 'max:150'],
            'revision' => ['nullable', 'string', 'max:20'],
            'file' => array_filter([
                'required', 'file', 'max:'.$config['max_kb'],
                'extensions:'.implode(',', $isImage ? $config['image_extensions'] : $config['extensions']),
                $isImage ? 'mimes:'.implode(',', $config['image_extensions']) : null,
            ]),
        ], ['file.extensions' => 'This file type is not allowed.']);

        $document = $storage->store($item, $request->file('file'), $data['category'], $data['title'] ?? null, $data['revision'] ?? null, $request->user());

        return (new DocumentResource($document->load('uploader')))->additional(['message' => 'Document uploaded.'])->response()->setStatusCode(201);
    }

    // ---- Helpers --------------------------------------------------------

    private function find(int $id): Item
    {
        return Item::query()->forCurrentCompany()->findOrFail($id);
    }

    private function filtered(Request $request): Builder
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'item_type' => ['nullable', Rule::in(array_keys(config('erp.master_data.item_types')))],
            'material_grade_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_purchased' => ['nullable', 'boolean'],
            'is_manufactured' => ['nullable', 'boolean'],
            'is_sold' => ['nullable', 'boolean'],
            'can_have_bom' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $categoryIds = isset($filters['category_id']) ? $this->categoryWithDescendants((int) $filters['category_id']) : null;

        return Item::query()->forCurrentCompany()
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(code) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(name) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(drawing_number) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(internal_part_number) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(customer_part_number) LIKE ?', [Search::like($s)])))
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->when($filters['item_type'] ?? null, fn ($q, $t) => $q->where('item_type', $t))
            ->when($filters['material_grade_id'] ?? null, fn ($q, $id) => $q->where('material_grade_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->when(isset($filters['is_purchased']), fn ($q) => $q->where('is_purchased', $request->boolean('is_purchased')))
            ->when(isset($filters['is_manufactured']), fn ($q) => $q->where('is_manufactured', $request->boolean('is_manufactured')))
            ->when(isset($filters['is_sold']), fn ($q) => $q->where('is_sold', $request->boolean('is_sold')))
            ->when($request->boolean('can_have_bom'), fn ($q) => $q->where(fn ($w) => $w
                ->where('is_manufactured', true)->orWhereIn('item_type', config('erp.master_data.manufactured_item_types'))))
            ->orderBy($filters['sort'] ?? 'code', $filters['direction'] ?? 'asc')
            ->orderBy('id');
    }

    /** @return list<int> */
    private function categoryWithDescendants(int $rootId): array
    {
        $all = ItemCategory::query()->where('company_id', Company::current()?->id)->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [$rootId];
        for ($i = 0; $i < count($ids) && $i < 5000; $i++) {
            foreach ($all->get($ids[$i], collect()) as $child) {
                $ids[] = $child->id;
            }
        }

        return $ids;
    }

    /** @return array{uom_id: int, factor: string, remarks: ?string, is_active?: bool} */
    private function validateConversion(Request $request, Item $item, ?ItemUomConversion $conversion = null): array
    {
        return $request->validate([
            'uom_id' => $conversion ? ['sometimes', Rule::in([$conversion->uom_id])]
                : ['required', 'integer', Rule::exists('units_of_measure', 'id')->where('is_active', true), Rule::unique('item_uom_conversions', 'uom_id')->where('item_id', $item->id)],
            'factor' => ['required', 'regex:/^\d{1,18}(\.\d{1,12})?$/'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'uom_id.unique' => 'This item already has a conversion for that unit.',
            'factor.regex' => 'Enter a positive factor with at most 12 decimal places.',
        ]) + ['uom_id' => $conversion?->uom_id];
    }

    private function saveConversion(Item $item, array $data, ?ItemUomConversion $conversion, string $field = 'factor'): ItemUomConversion
    {
        $uom = UnitOfMeasure::findOrFail($data['uom_id']);
        $item->loadMissing('baseUom');
        if ($errors = $this->uoms->validateItemConversion($item, $uom, (string) $data['factor'], $conversion?->id)) {
            throw ValidationException::withMessages([$field => $errors]);
        }

        $isNew = ! $conversion;
        $conversion ??= new ItemUomConversion(['item_id' => $item->id, 'uom_id' => $uom->id]);
        $before = $isNew ? [] : ['factor' => (string) $conversion->factor, 'remarks' => $conversion->remarks];
        $conversion->fill([
            'factor' => Decimal::normalize((string) $data['factor']),
            'remarks' => $data['remarks'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $conversion->item_id = $item->id;
        $conversion->uom_id = $uom->id;
        $conversion->save();

        $this->activity->log('item.uom_conversion_saved', $item, [
            'uom' => $uom->code, 'factor' => $conversion->factor, 'base' => $item->baseUom->code,
            'changes' => $isNew ? null : $this->activity->diff($before, ['factor' => (string) $conversion->factor, 'remarks' => $conversion->remarks]),
        ], "Set 1 {$uom->code} = {$conversion->factor} {$item->baseUom->code} for {$item->code}");

        return $conversion;
    }

    private function ensureUnitsConvertible(Item $item): void
    {
        $errors = [];
        foreach (['purchase_uom_id' => 'purchase', 'production_uom_id' => 'production'] as $field => $label) {
            if ($item->{$field} && ! $this->uoms->canConvert($item->{$field}, $item->base_uom_id, $item)) {
                $errors[$field] = "The {$label} unit cannot be converted to the base unit. Add an item-specific conversion (e.g. 1 BOX = 100 PCS) first.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function conversionInApprovedUse(Item $item, int $uomId): bool
    {
        return BomLine::query()->where('component_item_id', $item->id)->where('uom_id', $uomId)
            ->whereHas('bom', fn ($q) => $q->whereNotNull('approved_at'))->exists()
            || Bom::query()->where('item_id', $item->id)->where('output_uom_id', $uomId)->whereNotNull('approved_at')->exists();
    }
}
