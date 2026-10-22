<?php

namespace App\Models\Stock;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use App\Models\Department;
use App\Models\Factory;
use App\Models\User;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One inward, outward or opening-stock entry. Only posted documents affect
 * stock. Posted documents change only through reversal, or an audited Admin
 * correction/deletion (StockPostingService) that recalculates stock.
 */
class StockDocument extends Model
{
    use BelongsToCompany, SoftDeletes, TracksAuthors;

    public const INWARD = 'inward';

    public const OUTWARD = 'outward';

    public const OPENING = 'opening';

    public const TYPES = [self::INWARD, self::OUTWARD, self::OPENING];

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'document_date', 'factory_id', 'location_id', 'sku_id', 'vendor_id', 'department_id', 'quantity', 'received_qty',
        'rejected_qty', 'recipient', 'job_no', 'reference', 'remarks', 'vendor_check_reason',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'quantity' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'rejected_qty' => 'decimal:3',
            'vendor_mapped' => 'boolean',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /** Quantity this document adds to (+) or removes from (−) usable stock. */
    public function stockEffect(): string
    {
        return match ($this->type) {
            self::INWARD => bcsub((string) $this->received_qty, (string) $this->rejected_qty, 3),
            self::OUTWARD => bcmul((string) $this->quantity, '-1', 3),
            default => bcadd((string) $this->quantity, '0', 3),
        };
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class, 'document_id');
    }
}
