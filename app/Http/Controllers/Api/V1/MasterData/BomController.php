<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\BomRequest;
use App\Http\Resources\MasterData\BomResource;
use App\Models\MasterData\Bom;
use App\Services\ActivityLogger;
use App\Services\MasterData\BomService;
use App\Services\MasterData\DocumentLifecycle;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BomController extends VersionedDocumentController
{
    public function __construct(private readonly BomService $boms) {}

    protected function lifecycle(): DocumentLifecycle
    {
        return $this->boms;
    }

    protected function modelClass(): string
    {
        return Bom::class;
    }

    protected function resourceClass(): string
    {
        return BomResource::class;
    }

    protected function requestClass(): string
    {
        return BomRequest::class;
    }

    protected function numberColumn(): string
    {
        return 'bom_number';
    }

    protected function versionColumn(): string
    {
        return 'version';
    }

    protected function linesKey(): string
    {
        return 'lines';
    }

    protected function linesRelation(): string
    {
        return 'lines';
    }

    protected function detailRelations(): array
    {
        return ['item.baseUom', 'outputUom', 'lines.component.baseUom', 'lines.uom', 'approver', 'creator', 'revisedFrom'];
    }

    protected function saveDraft(?Model $document, array $header, array $lines): Model
    {
        return $this->boms->saveDraft($document, $header, $lines);
    }

    /** Multi-level structure using effective approved sub-BOMs. */
    public function structure(Request $request, int $id): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;

        return response()->json(['data' => $this->boms->structure($this->find($id), $date)]);
    }

    public function export(Request $request, int $id, ActivityLogger $activity): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        /** @var Bom $bom */
        $bom = $this->find($id)->load(['item', 'outputUom']);

        $activity->log('bom.exported', $bom, ['format' => $format], "Exported BOM {$bom->bom_number}");

        return Spreadsheet::download(
            "{$bom->bom_number}-v{$bom->version}",
            ['Level', 'Component Code', 'Component Name', 'Quantity', 'UOM', 'Scrap %', 'Qty incl. Scrap (Base UOM)', 'Base UOM', 'Sub-BOM', 'Remarks'],
            [
                ['0', $bom->item->code, $bom->item->name, (string) $bom->output_qty, $bom->outputUom->code, '', '', '', "{$bom->bom_number} v{$bom->version} ({$bom->status})", ''],
                ...$this->boms->exportRows($bom),
            ],
            $format,
        );
    }
}
