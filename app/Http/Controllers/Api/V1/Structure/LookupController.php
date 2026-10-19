<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Http\Resources\Structure\UserSummaryResource;
use App\Models\User;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lightweight pickers for structure forms, so assigning a manager does not
 * require the broader users.view permission. Returns minimal fields only.
 */
class LookupController extends Controller
{
    public function users(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'ids' => ['nullable', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $users = User::query()
            ->when($filters['ids'] ?? null,
                fn ($q, $ids) => $q->whereKey($ids),
                fn ($q) => $q->where('is_active', true))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [Search::like($s)])
                ->orWhereRaw('LOWER(email) LIKE ?', [Search::like($s)])
                ->orWhereRaw('LOWER(employee_code) LIKE ?', [Search::like($s)])))
            ->orderBy('first_name')->orderBy('last_name')
            ->limit(25)
            ->get();

        return UserSummaryResource::collection($users);
    }
}
