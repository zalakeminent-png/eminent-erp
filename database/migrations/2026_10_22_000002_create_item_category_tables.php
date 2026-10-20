<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Prefix for automatic SKU generation; inherited by subcategories when empty.
            $table->string('sku_prefix', 12)->nullable();
            // Suggested item type for new items in this category.
            $table->string('default_item_type', 30)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('parent_id');
            $table->index('name');
        });

        // Configurable item attribute definitions.
        Schema::create('item_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('data_type', 20); // text | number | select | boolean
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Which attributes apply to which category (inherited by subcategories).
        Schema::create('item_category_attribute', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('item_categories')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('item_attributes')->restrictOnDelete();
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->unique(['category_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_category_attribute');
        Schema::dropIfExists('item_attributes');
        Schema::dropIfExists('item_categories');
    }
};
