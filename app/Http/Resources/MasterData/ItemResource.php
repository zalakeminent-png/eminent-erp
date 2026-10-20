<?php

namespace App\Http\Resources\MasterData;

use App\Models\MasterData\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $image = $this->relationLoaded('documents') ? $this->documents->firstWhere('category', 'image') : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => Refs::coded($this->category)),
            'item_type' => $this->item_type,
            'item_type_label' => config("erp.master_data.item_types.{$this->item_type}", $this->item_type),
            'material_grade_id' => $this->material_grade_id,
            'material_grade' => $this->whenLoaded('materialGrade', fn () => Refs::coded($this->materialGrade)),
            'base_uom_id' => $this->base_uom_id,
            'base_uom' => $this->whenLoaded('baseUom', fn () => Refs::uom($this->baseUom)),
            'purchase_uom_id' => $this->purchase_uom_id,
            'purchase_uom' => $this->whenLoaded('purchaseUom', fn () => Refs::uom($this->purchaseUom)),
            'production_uom_id' => $this->production_uom_id,
            'production_uom' => $this->whenLoaded('productionUom', fn () => Refs::uom($this->productionUom)),
            'technical_specification' => $this->technical_specification,
            'material' => $this->material,
            'shape' => $this->shape,
            'size' => $this->size,
            'length' => Refs::decimal($this->length),
            'width' => Refs::decimal($this->width),
            'height' => Refs::decimal($this->height),
            'diameter' => Refs::decimal($this->diameter),
            'thickness' => Refs::decimal($this->thickness),
            'dimension_uom_id' => $this->dimension_uom_id,
            'dimension_uom' => $this->whenLoaded('dimensionUom', fn () => Refs::uom($this->dimensionUom)),
            'surface_finish' => $this->surface_finish,
            'color' => $this->color,
            'weight_per_piece' => Refs::decimal($this->weight_per_piece),
            'weight_uom_id' => $this->weight_uom_id,
            'weight_uom' => $this->whenLoaded('weightUom', fn () => Refs::uom($this->weightUom)),
            'customer_part_number' => $this->customer_part_number,
            'internal_part_number' => $this->internal_part_number,
            'drawing_number' => $this->drawing_number,
            'drawing_revision' => $this->drawing_revision,
            'additional_specifications' => $this->additional_specifications,
            'is_stock_item' => $this->is_stock_item,
            'track_inventory' => $this->track_inventory,
            'min_stock_level' => Refs::decimal($this->min_stock_level),
            'max_stock_level' => Refs::decimal($this->max_stock_level),
            'reorder_level' => Refs::decimal($this->reorder_level),
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_warehouse' => $this->whenLoaded('defaultWarehouse', fn () => Refs::coded($this->defaultWarehouse)),
            'default_location_id' => $this->default_location_id,
            'default_location' => $this->whenLoaded('defaultLocation', fn () => Refs::coded($this->defaultLocation)),
            'allow_negative_stock' => $this->allow_negative_stock,
            'requires_qc' => $this->requires_qc,
            'is_purchased' => $this->is_purchased,
            'is_manufactured' => $this->is_manufactured,
            'is_sold' => $this->is_sold,
            'can_have_bom' => $this->canHaveBom(),
            'revision' => $this->revision,
            'is_active' => $this->is_active,
            'image_url' => $image ? url("/api/v1/documents/{$image->id}/download?inline=1") : null,
            'attribute_values' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->map(fn ($v) => [
                'attribute_id' => $v->attribute_id,
                'code' => $v->attribute?->code,
                'name' => $v->attribute?->name,
                'data_type' => $v->attribute?->data_type,
                'value' => $v->value,
            ])->values()),
            'uom_conversions' => $this->whenLoaded('uomConversions', fn () => ItemUomConversionResource::collection($this->uomConversions)),
            ...Refs::audit($this->resource),
        ];
    }
}
