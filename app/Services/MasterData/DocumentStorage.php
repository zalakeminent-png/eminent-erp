<?php

namespace App\Services\MasterData;

use App\Models\MasterData\MasterDataDocument;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared attachment store for master data (drawings, images, specs).
 * Files live on a private disk under random names and are only served
 * through an authorised download endpoint.
 */
class DocumentStorage
{
    private const INLINE_MIME = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('erp.master_data.documents.disk'));
    }

    public function store(Model $owner, UploadedFile $file, string $category, ?string $title, ?string $revision, User $actor): MasterDataDocument
    {
        $folder = 'master-data/'.Str::kebab(class_basename($owner)).'/'.$owner->getKey();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs($folder, Str::random(40).($extension ? ".{$extension}" : ''), config('erp.master_data.documents.disk'));

        try {
            /** @var MasterDataDocument $document */
            $document = DB::transaction(fn () => $owner->documents()->create([
                'category' => $category,
                'title' => $title,
                'revision' => $revision,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $actor->id,
            ]));
        } catch (\Throwable $e) {
            $this->disk()->delete($path);
            throw $e;
        }

        $this->activity->log('document.uploaded', $owner, [
            'document_id' => $document->id, 'category' => $category, 'name' => $document->original_name, 'revision' => $revision,
        ], "Uploaded {$category} {$document->original_name}");

        return $document;
    }

    public function delete(MasterDataDocument $document): void
    {
        $owner = $document->documentable;
        $snapshot = ['document_id' => $document->id, 'category' => $document->category, 'name' => $document->original_name];

        DB::transaction(fn () => $document->delete());
        $this->disk()->delete($document->path);

        $this->activity->log('document.deleted', $owner, $snapshot, "Deleted {$document->category} {$document->original_name}");
    }

    public function download(MasterDataDocument $document, bool $inline = false): StreamedResponse
    {
        $inline = $inline && in_array($document->mime_type, self::INLINE_MIME, true);
        $headers = [
            'Content-Type' => $inline ? $document->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ];

        return $inline
            ? $this->disk()->response($document->path, $document->original_name, $headers)
            : $this->disk()->download($document->path, $document->original_name, $headers);
    }
}
