<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InventoryDocument;
use App\Models\MasterData\Item;
use App\Models\MasterData\MasterDataDocument;
use App\Services\Inventory\Documents\DocumentService;
use App\Services\MasterData\DocumentStorage;
use App\Services\Structure\StructureScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download / delete shared master data attachments. Access follows the
 * owning record's permissions.
 */
class DocumentController extends Controller
{
    /** owner class => [view permission, update permission] */
    private const PERMISSIONS = [
        Item::class => ['items.view', 'items.update'],
    ];

    public function download(Request $request, int $id, DocumentStorage $storage): StreamedResponse
    {
        $document = $this->authorizedDocument($request, $id, 0);

        return $storage->download($document, $request->boolean('inline'));
    }

    public function destroy(Request $request, int $id, DocumentStorage $storage): JsonResponse
    {
        $storage->delete($this->authorizedDocument($request, $id, 1));

        return response()->json(['message' => 'Document deleted.']);
    }

    private function authorizedDocument(Request $request, int $id, int $level): MasterDataDocument
    {
        $document = MasterDataDocument::query()->findOrFail($id);
        $permission = self::PERMISSIONS[$document->documentable_type][$level] ?? null;

        // Stock document attachments follow the owning document type's permissions and factory scope.
        if ($document->documentable_type === InventoryDocument::class && $document->documentable) {
            $handler = app(DocumentService::class)->handler($document->documentable->document_type);
            $permission = $level === 0 ? $handler->module().'.view' : $handler->editPermission();
            app(StructureScope::class)->ensureFactory($request->user(), $document->documentable->factory_id);
            abort_unless($level === 0 || $document->documentable->status === 'draft', 409, 'Attachments of submitted documents cannot be deleted.');
        }

        abort_unless($permission && $request->user()->hasAnyPermission(explode('|', $permission)), 403);
        abort_unless($document->documentable !== null, 404);

        return $document;
    }
}
