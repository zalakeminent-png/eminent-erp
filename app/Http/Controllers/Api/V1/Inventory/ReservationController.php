<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryRefs;
use App\Http\Resources\MasterData\Refs;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\ReservationEvent;
use App\Services\Inventory\ReservationService;
use App\Services\Structure\StructureScope;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Reservation::query()->with(['item', 'baseUom', 'uom', 'warehouse.factory', 'location.warehouse', 'reservedBy']);
        $this->scope->applyByFactory($query, $request->user());
        foreach (['status', 'item_id', 'warehouse_id', 'location_id', 'reference_type'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->boolean('open')) {
            $query->holding();
        }
        if ($term = trim((string) $request->query('search'))) {
            $like = Search::like($term);
            $query->where(fn ($q) => $q->whereRaw('LOWER(reservation_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(reference_number, \'\')) LIKE ?', [$like])
                ->orWhereHas('item', fn ($i) => $i->whereRaw('LOWER(code) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like])));
        }
        $sort = in_array($request->query('sort'), ['reservation_number', 'created_at', 'expires_on', 'status'], true) ? $request->query('sort') : 'created_at';
        $page = $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc')->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 15) ?: 15))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Reservation $r) => $this->row($r))->values(),
            'meta' => InventoryRefs::meta($page),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $reservation = $this->find($request, $id)->load(['item', 'baseUom', 'uom', 'warehouse.factory', 'location.warehouse', 'reservedBy', 'events.user']);

        return response()->json(['data' => $this->row($reservation) + [
            'events' => $reservation->events->map(fn (ReservationEvent $e) => [
                'id' => $e->id, 'action' => $e->action, 'base_quantity' => Refs::decimal($e->base_quantity), 'remarks' => $e->remarks,
                'user' => Refs::user($e->user), 'created_at' => $e->created_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'factory_id' => ['required_without_all:location_id,warehouse_id', 'nullable', 'integer', 'exists:factories,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'quantity' => ['required', 'regex:/^\d{1,14}(\.\d{1,6})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'uom_id' => ['required', 'integer', 'exists:units_of_measure,id'],
            'reference_type' => ['required', Rule::in(ReservationService::REFERENCE_TYPES)],
            'reference_id' => ['nullable', 'integer'],
            'reference_number' => ['required_if:reference_type,manual', 'nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ], ['quantity.regex' => 'Enter a positive quantity (max 6 decimals).', 'quantity.not_regex' => 'Quantity must be greater than zero.']);

        $reservation = $this->reservations->reserve($data, $request->user());

        return response()->json(['data' => $this->row($reservation->load(['item', 'baseUom', 'uom', 'warehouse.factory', 'location.warehouse', 'reservedBy'])), 'message' => "Reservation {$reservation->reservation_number} created."], 201);
    }

    public function release(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'base_quantity' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'cancel' => ['sometimes', 'boolean'],
        ]);
        $reservation = $this->reservations->release($this->find($request, $id), $data['base_quantity'] ?? null, $data['remarks'] ?? null, $request->user(), (bool) ($data['cancel'] ?? false));

        return response()->json(['data' => $this->row($reservation->load(['item', 'baseUom', 'uom', 'warehouse.factory', 'location.warehouse', 'reservedBy'])), 'message' => 'Reservation updated.']);
    }

    private function find(Request $request, int $id): Reservation
    {
        $reservation = Reservation::query()->findOrFail($id);
        $this->scope->ensureFactory($request->user(), $reservation->factory_id);

        return $reservation;
    }

    private function row(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'reservation_number' => $r->reservation_number,
            'item' => Refs::coded($r->item),
            'warehouse' => Refs::coded($r->warehouse),
            'factory' => Refs::coded($r->warehouse?->factory),
            'location' => InventoryRefs::location($r->location),
            'quantity' => Refs::decimal($r->quantity),
            'uom' => Refs::uom($r->uom),
            'base_quantity' => Refs::decimal($r->base_quantity),
            'released_base_quantity' => Refs::decimal($r->released_base_quantity),
            'open_base_quantity' => in_array($r->status, Reservation::OPEN, true) ? $r->openBaseQuantity() : '0',
            'base_uom' => Refs::uom($r->baseUom),
            'reference_type' => $r->reference_type,
            'reference_id' => $r->reference_id,
            'reference_number' => $r->reference_number,
            'status' => $r->status,
            'expires_on' => $r->expires_on?->toDateString(),
            'remarks' => $r->remarks,
            'reserved_by' => Refs::user($r->reservedBy),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
