<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\QcSpecification;
use Illuminate\Http\Request;

/** @mixin QcSpecification */
class QcSpecificationResource extends VersionedDocumentResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->header($request, 'spec_number', 'revision'),
            'inspection_instructions' => $this->inspection_instructions,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'parameter_id' => $l->parameter_id,
                'parameter' => $l->relationLoaded('parameter') ? [
                    'id' => $l->parameter->id, 'code' => $l->parameter->code, 'name' => $l->parameter->name,
                    'data_type' => $l->parameter->data_type, 'options' => $l->parameter->options ?? [],
                ] : null,
                'process_id' => $l->process_id,
                'process' => $l->relationLoaded('process') ? Refs::coded($l->process) : null,
                'target_value' => Refs::decimal($l->target_value),
                'min_value' => Refs::decimal($l->min_value),
                'max_value' => Refs::decimal($l->max_value),
                'uom_id' => $l->uom_id,
                'uom' => $l->relationLoaded('uom') ? Refs::uom($l->uom) : null,
                'expected_text' => $l->expected_text,
                'allowed_options' => $l->allowed_options ?? [],
                'inspection_method' => $l->inspection_method,
                'instructions' => $l->instructions,
                'is_required' => $l->is_required,
            ])->values()),
        ];
    }
}
