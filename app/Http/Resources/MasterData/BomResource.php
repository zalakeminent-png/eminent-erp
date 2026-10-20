<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\Bom;
use Illuminate\Http\Request;

/** @mixin Bom */
class BomResource extends VersionedDocumentResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->header($request, 'bom_number', 'version'),
            'output_qty' => Refs::decimal($this->output_qty),
            'output_uom_id' => $this->output_uom_id,
            'output_uom' => $this->whenLoaded('outputUom', fn () => Refs::uom($this->outputUom)),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'component_item_id' => $l->component_item_id,
                'component' => $l->relationLoaded('component') ? [
                    'id' => $l->component->id, 'code' => $l->component->code, 'name' => $l->component->name,
                    'item_type' => $l->component->item_type, 'is_active' => $l->component->is_active,
                    'base_uom' => $l->component->relationLoaded('baseUom') ? Refs::uom($l->component->baseUom) : null,
                ] : null,
                'quantity' => Refs::decimal($l->quantity),
                'uom_id' => $l->uom_id,
                'uom' => $l->relationLoaded('uom') ? Refs::uom($l->uom) : null,
                'scrap_percent' => Refs::decimal($l->scrap_percent),
                'remarks' => $l->remarks,
            ])->values()),
        ];
    }
}
