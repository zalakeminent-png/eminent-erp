<?php

namespace App\Models\MasterData;

use App\Models\Concerns\TracksAuthors;
use App\Models\Concerns\VersionedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Manufacturing sequence for a product. Creates no production jobs. */
class ProductRouting extends Model
{
    use TracksAuthors, VersionedDocument;

    protected $fillable = ['item_id', 'description', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'routing_id')->orderBy('sequence');
    }
}
