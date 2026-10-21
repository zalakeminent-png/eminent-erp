<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inventory_reservation_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_quantity' => 'decimal:6', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
