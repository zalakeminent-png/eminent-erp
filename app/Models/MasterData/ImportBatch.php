<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit record of an Excel/CSV import (upload → validate → commit). */
class ImportBatch extends Model
{
    use BelongsToCompany;

    public const UPLOADED = 'uploaded';

    public const VALIDATED = 'validated';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $fillable = ['entity', 'original_name', 'path', 'status', 'headers', 'mapping', 'update_existing', 'created_by'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'row_results' => 'array',
            'update_existing' => 'boolean',
            'validated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
