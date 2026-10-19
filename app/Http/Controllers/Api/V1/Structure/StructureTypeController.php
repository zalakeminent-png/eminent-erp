<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StructureTypeRequest;
use App\Http\Resources\Structure\StructureTypeResource;
use App\Models\StructureType;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Configurable department and warehouse types. Types are never deleted
 * (they may be referenced); deactivate them instead.
 */
class StructureTypeController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(StructureType::CATEGORIES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $types = StructureType::query()
            ->select('structure_types.*')
            ->selectRaw('(SELECT COUNT(*) FROM departments WHERE departments.type_id = structure_types.id)
                + (SELECT COUNT(*) FROM warehouses WHERE warehouses.type_id = structure_types.id) AS usage_count')
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->orderBy('category')->orderBy('sort_order')->orderBy('name')
            ->get();

        return StructureTypeResource::collection($types);
    }

    public function store(StructureTypeRequest $request): JsonResponse
    {
        $type = StructureType::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            // Default: next sequential position within the category.
            'sort_order' => $request->filled('sort_order')
                ? $request->integer('sort_order')
                : (int) StructureType::where('category', $request->input('category'))->max('sort_order') + 1,
        ]);

        $this->activity->log('structure-type.created', $type, ['category' => $type->category, 'name' => $type->name], "Created {$type->category} type {$type->name}");

        return (new StructureTypeResource($type))->additional(['message' => 'Type created.'])->response()->setStatusCode(201);
    }

    public function update(StructureTypeRequest $request, StructureType $structureType): StructureTypeResource
    {
        $data = collect($request->validated())->except('category')->all();
        // An empty sort order keeps the current position.
        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }
        $before = $structureType->only(array_keys($data));
        $structureType->update($data);

        if ($changes = $this->activity->diff($before, $structureType->only(array_keys($data)))) {
            $this->activity->log('structure-type.updated', $structureType, ['changes' => $changes], "Updated {$structureType->category} type {$structureType->name}");
        }

        return new StructureTypeResource($structureType);
    }
}
