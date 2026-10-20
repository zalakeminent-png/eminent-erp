<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\QcSpecificationRequest;
use App\Http\Resources\MasterData\QcSpecificationResource;
use App\Models\MasterData\QcSpecification;
use App\Services\MasterData\DocumentLifecycle;
use App\Services\MasterData\QcSpecificationService;
use Illuminate\Database\Eloquent\Model;

class QcSpecificationController extends VersionedDocumentController
{
    public function __construct(private readonly QcSpecificationService $specs) {}

    protected function lifecycle(): DocumentLifecycle
    {
        return $this->specs;
    }

    protected function modelClass(): string
    {
        return QcSpecification::class;
    }

    protected function resourceClass(): string
    {
        return QcSpecificationResource::class;
    }

    protected function requestClass(): string
    {
        return QcSpecificationRequest::class;
    }

    protected function numberColumn(): string
    {
        return 'spec_number';
    }

    protected function versionColumn(): string
    {
        return 'revision';
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
        return ['item.baseUom', 'lines.parameter', 'lines.process', 'lines.uom', 'approver', 'creator', 'revisedFrom'];
    }

    protected function saveDraft(?Model $document, array $header, array $lines): Model
    {
        return $this->specs->saveDraft($document, $header, $lines);
    }
}
