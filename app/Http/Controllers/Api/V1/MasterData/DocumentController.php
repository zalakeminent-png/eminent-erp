<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Controller;
use App\Models\MasterData\Item;
use App\Models\MasterData\MasterDataDocument;
use App\Services\MasterData\DocumentStorage;
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

        abort_unless($permission && $request->user()->can($permission), 403);
        abort_unless($document->documentable !== null, 404);

        return $document;
    }
}
