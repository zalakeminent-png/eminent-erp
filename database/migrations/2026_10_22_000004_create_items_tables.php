<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Running numbers for generated codes (SKUs, BOM/routing/spec numbers).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('key', 80);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('item_categories')->restrictOnDelete();
            $table->string('item_type', 30)->index();
            $table->foreignId('material_grade_id')->nullable()->constrained('material_grades')->restrictOnDelete();
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('production_uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->text('technical_specification')->nullable();

            // Product attributes (all optional).
            $table->string('material', 100)->nullable();
            $table->string('shape', 60)->nullable();
            $table->string('size', 60)->nullable();
            $table->decimal('length', 18, 4)->nullable();
            $table->decimal('width', 18, 4)->nullable();
            $table->decimal('height', 18, 4)->nullable();
            $table->decimal('diameter', 18, 4)->nullable();
            $table->decimal('thickness', 18, 4)->nullable();
            $table->foreignId('dimension_uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->string('surface_finish', 100)->nullable();
            $table->string('color', 60)->nullable();
            $table->decimal('weight_per_piece', 18, 6)->nullable();
            $table->foreignId('weight_uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->string('customer_part_number', 80)->nullable();
            $table->string('internal_part_number', 80)->nullable();
            $table->string('drawing_number', 80)->nullable();
            $table->string('drawing_revision', 20)->nullable();
            $table->text('additional_specifications')->nullable();

            // Stock configuration only — balances live in the Inventory module.
            $table->boolean('is_stock_item')->default(true);
            $table->boolean('track_inventory')->default(true);
            $table->decimal('min_stock_level', 18, 4)->nullable();
            $table->decimal('max_stock_level', 18, 4)->nullable();
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('default_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('requires_qc')->default(false);
            $table->boolean('is_purchased')->default(false);
            $table->boolean('is_manufactured')->default(false);
            $table->boolean('is_sold')->default(false);

            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('name');
            $table->index('category_id');
            $table->index('drawing_number');
        });

        Schema::create('item_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('item_attributes')->restrictOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'attribute_id']);
        });

        // Item-specific conversions: 1 uom = factor × item base UOM (e.g. 1 BOX = 100 PCS).
        Schema::create('item_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('factor', 30, 12);
            $table->string('remarks', 255)->nullable(); // documentation / source of the factor
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'uom_id']);
        });

        // Specification revision history (append-only).
        Schema::create('item_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('changes');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['item_id', 'revision']);
        });

        // Shared attachment store for master data (private disk).
        Schema::create('master_data_documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('category', 30); // image | drawing | specification | other
            $table->string('title', 150)->nullable();
            $table->string('revision', 20)->nullable();
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_documents');
        Schema::dropIfExists('item_revisions');
        Schema::dropIfExists('item_uom_conversions');
        Schema::dropIfExists('item_attribute_values');
        Schema::dropIfExists('items');
        Schema::dropIfExists('number_sequences');
    }
};
