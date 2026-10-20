<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcSpecificationLine extends Model
{
    protected $fillable = [
        'line_no', 'parameter_id', 'process_id', 'target_value', 'min_value', 'max_value', 'uom_id',
        'expected_text', 'allowed_options', 'inspection_method', 'instructions', 'is_required',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'target_value' => 'decimal:6',
            'min_value' => 'decimal:6',
            'max_value' => 'decimal:6',
            'allowed_options' => 'array',
            'is_required' => 'boolean',
        ];
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(QcParameter::class, 'parameter_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(ManufacturingProcess::class, 'process_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }
}
