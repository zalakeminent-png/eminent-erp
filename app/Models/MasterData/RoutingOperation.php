<?php

namespace App\Models\MasterData;

use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingOperation extends Model
{
    protected $fillable = [
        'sequence', 'process_id', 'department_id', 'machine_id', 'setup_time', 'cycle_time', 'time_unit',
        'process_loss_percent', 'requires_qc', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'setup_time' => 'decimal:4',
            'cycle_time' => 'decimal:4',
            'process_loss_percent' => 'decimal:3',
            'requires_qc' => 'boolean',
        ];
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(ProductRouting::class, 'routing_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(ManufacturingProcess::class, 'process_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
