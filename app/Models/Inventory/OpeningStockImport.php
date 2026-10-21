<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningStockImport extends Model
{
    use BelongsToCompany;

    public const UPLOADED = 'uploaded';

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const POSTING = 'posting';

    public const POSTED = 'posted';

    public const FAILED = 'failed';

    public const REVERSED = 'reversed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OpeningStockRow::class, 'import_id')->orderBy('row_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }
}
