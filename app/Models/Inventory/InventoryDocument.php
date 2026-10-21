<?php

namespace App\Models\Inventory;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use App\Models\Department;
use App\Models\Factory;
use App\Models\MasterData\BusinessPartner;
use App\Models\MasterData\MasterDataDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Stock document header (GRN, transfer, issue, return, adjustment, scrap,
 * QC disposition). Workflow and posting rules live in the type handlers.
 */
class InventoryDocument extends Model
{
    use BelongsToCompany, TracksAuthors;

    public const GRN = 'grn';

    public const TRANSFER = 'transfer';

    public const MATERIAL_ISSUE = 'material_issue';

    public const MATERIAL_RETURN = 'material_return';

    public const ADJUSTMENT = 'adjustment';

    public const SCRAP = 'scrap';

    public const QC_DISPOSITION = 'qc_disposition';

    protected $fillable = [
        'document_date', 'warehouse_id', 'source_location_id', 'destination_location_id', 'department_id', 'supplier_id',
        'supplier_invoice_number', 'reference_number', 'production_order_id', 'original_document_id', 'reason_code_id',
        'requires_handover', 'decision', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'requires_handover' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'received_at' => 'datetime',
            'posted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class, 'document_id')->orderBy('line_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class, 'document_id')->orderBy('id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(MasterDataDocument::class, 'documentable')->latest();
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'destination_location_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'supplier_id');
    }

    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_document_id');
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
