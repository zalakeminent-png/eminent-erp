<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned engineering documents: BOMs, routings and QC specifications.
 * Lifecycle: draft → approved (immutable) → inactive. Changes to an approved
 * version create a new draft version (revised_from_id keeps the lineage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->string('bom_number', 40)->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('output_qty', 18, 6);
            $table->foreignId('output_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revised_from_id')->nullable()->constrained('boms')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'version']);
        });

        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('boms')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('component_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('scrap_percent', 6, 3)->default(0);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->unique(['bom_id', 'component_item_id']);
            $table->index('component_item_id');
        });

        Schema::create('product_routings', function (Blueprint $table) {
            $table->id();
            $table->string('routing_number', 40)->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('description')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revised_from_id')->nullable()->constrained('product_routings')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'version']);
        });

        Schema::create('routing_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('product_routings')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('process_id')->constrained('manufacturing_processes')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->restrictOnDelete();
            $table->decimal('setup_time', 12, 4)->default(0);
            $table->decimal('cycle_time', 12, 4)->default(0); // per 1 unit of the item's base UOM
            $table->string('time_unit', 10)->default('minute');
            $table->decimal('process_loss_percent', 6, 3)->default(0);
            $table->boolean('requires_qc')->default(false);
            $table->string('remarks', 255)->nullable();
            $table->timestamps();

            $table->unique(['routing_id', 'sequence']);
        });

        Schema::create('qc_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('data_type', 20); // numeric | pass_fail | text | selection
            $table->foreignId('default_uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->json('options')->nullable(); // selection choices
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('qc_specifications', function (Blueprint $table) {
            $table->id();
            $table->string('spec_number', 40)->unique();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->text('description')->nullable();
            $table->text('inspection_instructions')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('revised_from_id')->nullable()->constrained('qc_specifications')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'revision']);
        });

        Schema::create('qc_specification_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specification_id')->constrained('qc_specifications')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('parameter_id')->constrained('qc_parameters')->restrictOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('manufacturing_processes')->restrictOnDelete();
            $table->decimal('target_value', 20, 6)->nullable();
            $table->decimal('min_value', 20, 6)->nullable();
            $table->decimal('max_value', 20, 6)->nullable();
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->string('expected_text', 255)->nullable();
            $table->json('allowed_options')->nullable();
            $table->string('inspection_method', 150)->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->index('specification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_specification_lines');
        Schema::dropIfExists('qc_specifications');
        Schema::dropIfExists('qc_parameters');
        Schema::dropIfExists('routing_operations');
        Schema::dropIfExists('product_routings');
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('boms');
    }
};
