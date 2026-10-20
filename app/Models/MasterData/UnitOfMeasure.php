<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    use TracksAuthors;

    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'name', 'dimension', 'decimal_precision', 'is_active'];

    protected function casts(): array
    {
        return [
            'decimal_precision' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Whether universal conversions are allowed for this unit's dimension. */
    public function isConvertible(): bool
    {
        return (bool) config("erp.master_data.uom_dimensions.{$this->dimension}.convertible", false);
    }
}
