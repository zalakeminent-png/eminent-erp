<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use App\Models\Concerns\VersionedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bill of materials: planned material requirement. Never moves stock. */
class Bom extends Model
{
    use TracksAuthors, VersionedDocument;

    protected $fillable = ['item_id', 'output_qty', 'output_uom_id', 'description', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'output_qty' => 'decimal:6',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function outputUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'output_uom_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('line_no');
    }
}
