<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Requests\MasterData\RoutingRequest;
use App\Http\Resources\MasterData\RoutingResource;
use App\Models\MasterData\ProductRouting;
use App\Services\MasterData\DocumentLifecycle;
use App\Services\MasterData\RoutingService;
use Illuminate\Database\Eloquent\Model;

class RoutingController extends VersionedDocumentController
{
    public function __construct(private readonly RoutingService $routings) {}

    protected function lifecycle(): DocumentLifecycle
    {
        return $this->routings;
    }

    protected function modelClass(): string
    {
        return ProductRouting::class;
    }

    protected function resourceClass(): string
    {
        return RoutingResource::class;
    }

    protected function requestClass(): string
    {
        return RoutingRequest::class;
    }

    protected function numberColumn(): string
    {
        return 'routing_number';
    }

    protected function versionColumn(): string
    {
        return 'version';
    }

    protected function linesKey(): string
    {
        return 'operations';
    }

    protected function linesRelation(): string
    {
        return 'operations';
    }

    protected function detailRelations(): array
    {
        return ['item.baseUom', 'operations.process', 'operations.department', 'operations.machine', 'approver', 'creator', 'revisedFrom'];
    }

    protected function saveDraft(?Model $document, array $header, array $lines): Model
    {
        return $this->routings->saveDraft($document, $header, $lines);
    }
}
