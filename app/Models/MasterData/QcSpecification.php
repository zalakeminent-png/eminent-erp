<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use App\Models\Concerns\VersionedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Product QC specification. Inspections themselves belong to Module 8. */
class QcSpecification extends Model
{
    use TracksAuthors, VersionedDocument;

    protected $fillable = ['item_id', 'description', 'inspection_instructions', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QcSpecificationLine::class, 'specification_id')->orderBy('line_no');
    }
}
