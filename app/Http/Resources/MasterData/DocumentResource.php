<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\MasterDataDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MasterDataDocument */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'title' => $this->title,
            'revision' => $this->revision,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'download_url' => url("/api/v1/documents/{$this->id}/download"),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => Refs::user($this->uploader)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
