<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\MasterData\Item;
use App\Models\MasterData\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Logical allocation of available stock; never a physical movement. */
class Reservation extends Model
{
    use BelongsToCompany;

    public const ACTIVE = 'active';

    public const PARTIALLY_RELEASED = 'partially_released';

    public const RELEASED = 'released';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Statuses that still hold quantity. */
    public const OPEN = [self::ACTIVE, self::PARTIALLY_RELEASED];

    protected $table = 'inventory_reservations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'released_base_quantity' => 'decimal:6',
            'expires_on' => 'date',
        ];
    }

    /** Open and not past its expiry date. */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::OPEN)
            ->where(fn ($q) => $q->whereNull($this->qualifyColumn('expires_on'))->orWhereDate($this->qualifyColumn('expires_on'), '>=', now()->toDateString()));
    }

    public function openBaseQuantity(): string
    {
        return bcsub(Decimal::normalize((string) $this->base_quantity), Decimal::normalize((string) $this->released_base_quantity), 6);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReservationEvent::class)->orderBy('id');
    }
}
