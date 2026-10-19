<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Read-only: there are deliberately no write endpoints for activity logs.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $logs = ActivityLog::query()
            ->with('actor')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $term = '%'.mb_strtolower(addcslashes($search, '%_\\')).'%';
                $query->where(fn ($q) => $q
                    ->whereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(action) LIKE ?', [$term]));
            })
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['actor_id'] ?? null, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($filters['entity_id'] ?? null, fn ($q, $id) => $q->where('entity_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('created_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ActivityLogResource::collection($logs)->additional([
            'meta' => [
                'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
            ],
        ]);
    }
}
