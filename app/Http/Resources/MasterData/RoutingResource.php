<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\ProductRouting;
use Illuminate\Http\Request;

/** @mixin ProductRouting */
class RoutingResource extends VersionedDocumentResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->header($request, 'routing_number', 'version'),
            'operations_count' => $this->whenCounted('operations'),
            'operations' => $this->whenLoaded('operations', fn () => $this->operations->map(fn ($o) => [
                'id' => $o->id,
                'sequence' => $o->sequence,
                'process_id' => $o->process_id,
                'process' => $o->relationLoaded('process') ? Refs::coded($o->process) : null,
                'department_id' => $o->department_id,
                'department' => $o->relationLoaded('department') ? Refs::coded($o->department) : null,
                'machine_id' => $o->machine_id,
                'machine' => $o->relationLoaded('machine') ? Refs::coded($o->machine) : null,
                'setup_time' => Refs::decimal($o->setup_time),
                'cycle_time' => Refs::decimal($o->cycle_time),
                'time_unit' => $o->time_unit,
                'process_loss_percent' => Refs::decimal($o->process_loss_percent),
                'requires_qc' => $o->requires_qc,
                'remarks' => $o->remarks,
            ])->values()),
        ];
    }
}
