<?php

namespace App\Http\Controllers\Api\V1\Structure;

use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Services\ActivityLogger;
use App\Services\Structure\StructureScope;
use App\Services\Structure\StructureTreeBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FactoryStructureController extends Controller
{
    public function __construct(
        private readonly StructureTreeBuilder $builder,
        private readonly StructureScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json([
            'data' => $this->builder->build($request->user(), $filters['search'] ?? null, $filters['status'] ?? null),
        ]);
    }

    public function show(Request $request, Factory $factory): JsonResponse
    {
        $this->scope->ensureFactory($request->user(), $factory->id);
        $filters = $this->filters($request);

        return response()->json([
            'data' => $this->builder->build($request->user(), $filters['search'] ?? null, $filters['status'] ?? null, $factory->id),
        ]);
    }

    public function export(Request $request, ActivityLogger $activity): StreamedResponse
    {
        $filters = $this->filters($request);
        $structure = $this->builder->build($request->user(), $filters['search'] ?? null, $filters['status'] ?? null);
        $rows = $this->builder->rows($structure);

        $activity->log('factory-structure.exported', null, ['rows' => count($rows), 'filters' => $filters], 'Exported factory structure');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['Factory Code', 'Factory', 'Level', 'Code', 'Name', 'Parent Path', 'Type', 'Status']);
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection.
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, 'factory-structure-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{search?: string|null, status?: string|null} */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
    }
}
