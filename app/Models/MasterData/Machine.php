<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use App\Models\Department;
use App\Models\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Machine or workstation (Module 2 factory + department). Capacity is
 * capacity_qty [capacity_uom] per capacity_period of capacity_period_hours,
 * e.g. 500 PCS per shift of 8 hours. No maintenance/monitoring here.
 */
class Machine extends Model
{
    use TracksAuthors;

    protected $fillable = [
        'factory_id', 'department_id', 'code', 'name', 'machine_type', 'workstation', 'manufacturer', 'model',
        'capacity_qty', 'capacity_uom_id', 'capacity_period', 'capacity_period_hours', 'working_hours_per_day',
        'remarks', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity_qty' => 'decimal:4',
            'capacity_period_hours' => 'decimal:2',
            'working_hours_per_day' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function capacityUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'capacity_uom_id');
    }

    public function processes(): BelongsToMany
    {
        return $this->belongsToMany(ManufacturingProcess::class, 'machine_process', 'machine_id', 'process_id')->withTimestamps();
    }
}
