<?php

namespace App\Models\MasterData;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksAuthors;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Item / product master. Holds definition and stock *configuration* only;
 * live quantities are calculated by the Inventory module.
 */
class Item extends Model
{
    use BelongsToCompany, TracksAuthors;

    /** Changes to these fields bump the specification revision. */
    public const SPEC_FIELDS = [
        'name', 'description', 'material_grade_id', 'base_uom_id', 'technical_specification', 'material', 'shape', 'size',
        'length', 'width', 'height', 'diameter', 'thickness', 'dimension_uom_id', 'surface_finish', 'color',
        'weight_per_piece', 'weight_uom_id', 'customer_part_number', 'internal_part_number', 'drawing_number',
        'drawing_revision', 'additional_specifications',
    ];

    public const DECIMAL_FIELDS = [
        'length', 'width', 'height', 'diameter', 'thickness', 'weight_per_piece',
        'min_stock_level', 'max_stock_level', 'reorder_level',
    ];

    protected $fillable = [
        'code', 'name', 'description', 'category_id', 'item_type', 'material_grade_id', 'base_uom_id', 'purchase_uom_id',
        'production_uom_id', 'technical_specification', 'material', 'shape', 'size', 'length', 'width', 'height', 'diameter',
        'thickness', 'dimension_uom_id', 'surface_finish', 'color', 'weight_per_piece', 'weight_uom_id', 'customer_part_number',
        'internal_part_number', 'drawing_number', 'drawing_revision', 'additional_specifications', 'is_stock_item',
        'track_inventory', 'min_stock_level', 'max_stock_level', 'reorder_level', 'default_warehouse_id', 'default_location_id',
        'allow_negative_stock', 'requires_qc', 'is_purchased', 'is_manufactured', 'is_sold', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'decimal:4',
            'width' => 'decimal:4',
            'height' => 'decimal:4',
            'diameter' => 'decimal:4',
            'thickness' => 'decimal:4',
            'weight_per_piece' => 'decimal:6',
            'min_stock_level' => 'decimal:4',
            'max_stock_level' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'is_stock_item' => 'boolean',
            'track_inventory' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'requires_qc' => 'boolean',
            'is_purchased' => 'boolean',
            'is_manufactured' => 'boolean',
            'is_sold' => 'boolean',
            'is_active' => 'boolean',
            'revision' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function materialGrade(): BelongsTo
    {
        return $this->belongsTo(MaterialGrade::class, 'material_grade_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'purchase_uom_id');
    }

    public function productionUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'production_uom_id');
    }

    public function dimensionUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'dimension_uom_id');
    }

    public function weightUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'weight_uom_id');
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'default_location_id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ItemAttributeValue::class);
    }

    public function uomConversions(): HasMany
    {
        return $this->hasMany(ItemUomConversion::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ItemRevision::class)->orderByDesc('revision');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(MasterDataDocument::class, 'documentable')->latest();
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class);
    }

    public function routings(): HasMany
    {
        return $this->hasMany(ProductRouting::class);
    }

    public function qcSpecifications(): HasMany
    {
        return $this->hasMany(QcSpecification::class);
    }

    public function canHaveBom(): bool
    {
        return $this->is_manufactured || in_array($this->item_type, config('erp.master_data.manufactured_item_types'), true);
    }
}
