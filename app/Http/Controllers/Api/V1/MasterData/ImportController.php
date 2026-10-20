<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MasterData\ImportBatch;
use App\Services\MasterData\Import\Importer;
use App\Services\MasterData\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel/CSV import endpoints. Requires master-data.import plus the target
 * entity's create permission (and update permission to update records).
 */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    /** Import types the user may use, with their columns. */
    public function types(Request $request): JsonResponse
    {
        return response()->json(['data' => collect($this->imports->importers())
            ->filter(fn (Importer $i) => $request->user()->can($i->createPermission()))
            ->map(fn (Importer $i, string $key) => [
                'entity' => $key,
                'label' => $i->label(),
                'can_update' => $request->user()->can($i->updatePermission()),
                'columns' => collect($i->columns())->map(fn ($c, $k) => ['key' => $k, 'label' => $c['label'], 'required' => ! empty($c['required'])])->values(),
            ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $batches = ImportBatch::query()->where('company_id', Company::current()?->id)
            ->with('creator')->latest()->paginate(15);

        return response()->json([
            'data' => $batches->getCollection()->map(fn (ImportBatch $b) => $this->summary($b)),
            'meta' => collect($batches->toArray())->only(['current_page', 'last_page', 'per_page', 'total', 'from', 'to']),
        ]);
    }

    public function template(Request $request, string $entity): BinaryFileResponse
    {
        $this->authorizeEntity($request, $entity);

        return $this->imports->template($entity);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(array_keys($this->imports->importers()))],
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.config('erp.master_data.import.max_kb')],
        ], ['file.extensions' => 'Upload an .xlsx or .csv file.']);
        $this->authorizeEntity($request, $data['entity']);

        $batch = $this->imports->upload($data['entity'], $request->file('file'), $request->user());

        return response()->json(['message' => 'File uploaded. Check the column mapping.', 'data' => $this->detail($batch)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->batch($request, $id))]);
    }

    public function validateBatch(Request $request, int $id): JsonResponse
    {
        $batch = $this->batch($request, $id);
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'update_existing' => ['required', 'boolean'],
        ]);
        if ($data['update_existing']) {
            abort_unless($request->user()->can($this->imports->importer($batch->entity)->updatePermission()), 403, 'You may not update existing records.');
        }

        $batch = $this->imports->validate($batch, $data['mapping'], (bool) $data['update_existing']);

        return response()->json(['message' => 'Validation finished.', 'data' => $this->detail($batch)]);
    }

    public function commit(Request $request, int $id): JsonResponse
    {
        $batch = $this->batch($request, $id);
        $data = $request->validate(['confirm' => ['accepted'], 'confirm_updates' => ['sometimes', 'boolean']], ['confirm.accepted' => 'Confirm the import to continue.']);

        $batch = $this->imports->commit($batch, (bool) ($data['confirm_updates'] ?? false));

        return response()->json([
            'message' => $batch->status === ImportBatch::PROCESSING ? 'Import queued. This page updates when it finishes.' : 'Import finished.',
            'data' => $this->detail($batch),
        ]);
    }

    public function errors(Request $request, int $id): BinaryFileResponse
    {
        return $this->imports->errorReport($this->batch($request, $id));
    }

    private function batch(Request $request, int $id): ImportBatch
    {
        $batch = ImportBatch::query()->where('company_id', Company::current()?->id)->findOrFail($id);
        $this->authorizeEntity($request, $batch->entity);

        return $batch;
    }

    private function authorizeEntity(Request $request, string $entity): void
    {
        abort_unless($request->user()->can($this->imports->importer($entity)->createPermission()), 403);
    }

    /** @return array<string, mixed> */
    private function summary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'entity' => $batch->entity,
            'original_name' => $batch->original_name,
            'status' => $batch->status,
            'update_existing' => $batch->update_existing,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'error_rows' => $batch->error_rows,
            'create_count' => $batch->create_count,
            'update_count' => $batch->update_count,
            'created_count' => $batch->created_count,
            'updated_count' => $batch->updated_count,
            'skipped_count' => $batch->skipped_count,
            'failure_message' => $batch->failure_message,
            'created_by' => $batch->creator?->full_name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ImportBatch $batch): array
    {
        $importer = $this->imports->importer($batch->entity);
        $results = collect($batch->row_results ?? []);

        return [
            ...$this->summary($batch->loadMissing('creator')),
            'headers' => $batch->headers ?? [],
            'mapping' => $batch->mapping ?? [],
            'columns' => collect($importer->columns())->map(fn ($c, $k) => ['key' => $k, 'label' => $c['label'], 'required' => ! empty($c['required'])])->values(),
            // Preview: first 100 rows plus up to 200 error rows.
            'preview' => $results->take(100)->values(),
            'errors' => $results->where('action', 'error')->take(200)->values(),
        ];
    }
}
