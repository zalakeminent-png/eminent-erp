<?php

namespace App\Http\Resources\MasterData;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Common header fields of BOMs, routings and QC specifications. */
abstract class VersionedDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    protected function header(Request $request, string $numberColumn, string $versionColumn): array
    {
        return [
            'id' => $this->id,
            'number' => $this->{$numberColumn},
            'version' => $this->{$versionColumn},
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id, 'code' => $this->item->code, 'name' => $this->item->name,
                'base_uom' => $this->item->relationLoaded('baseUom') ? Refs::uom($this->item->baseUom) : null,
            ]),
            'description' => $this->description,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status,
            'is_editable' => $this->isDraft(),
            'approved_by' => $this->whenLoaded('approver', fn () => Refs::user($this->approver)),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'revised_from_id' => $this->revised_from_id,
            'revised_from' => $this->whenLoaded('revisedFrom', fn () => $this->revisedFrom ? [
                'id' => $this->revisedFrom->id, 'number' => $this->revisedFrom->{$numberColumn}, 'version' => $this->revisedFrom->{$versionColumn},
            ] : null),
            ...Refs::audit($this->resource),
        ];
    }
}
