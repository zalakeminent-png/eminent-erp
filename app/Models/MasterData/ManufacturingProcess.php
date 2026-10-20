<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use App\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ManufacturingProcess extends Model
{
    use BelongsToCompany, TracksAuthors;

    public const TYPES = ['internal', 'external'];

    protected $fillable = ['code', 'name', 'description', 'process_type', 'default_department_id', 'requires_qc', 'is_active'];

    protected function casts(): array
    {
        return [
            'requires_qc' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function defaultDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'default_department_id');
    }

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(Machine::class, 'machine_process', 'process_id', 'machine_id')->withTimestamps();
    }
}
