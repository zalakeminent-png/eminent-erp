<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCountSession extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'draft';

    public const COUNTING = 'counting';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    /** Sessions in these states lock their locations against other postings. */
    public const LOCKING = [self::COUNTING, self::SUBMITTED, self::APPROVED];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'location_ids' => 'array',
            'category_ids' => 'array',
            'item_ids' => 'array',
            'lock_locations' => 'boolean',
            'cutoff_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'session_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
