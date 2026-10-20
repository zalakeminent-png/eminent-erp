<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcParameter extends Model
{
    use BelongsToCompany, TracksAuthors;

    public const NUMERIC = 'numeric';

    public const PASS_FAIL = 'pass_fail';

    public const TEXT = 'text';

    public const SELECTION = 'selection';

    protected $fillable = ['code', 'name', 'description', 'data_type', 'default_uom_id', 'options', 'is_active'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function defaultUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'default_uom_id');
    }
}
