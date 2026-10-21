<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock documents (GRN, transfer, material issue/return, adjustment,
 * scrap/rejection, QC disposition) share one header/line structure and an
 * approval timeline. Documents never change stock directly; posting goes
 * through the StockPostingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reason_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('category', 30); // scrap | rejection | adjustment | return | qc | transfer | general
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('inventory_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_type', 30)->index();
            $table->string('document_number', 40)->unique();
            $table->date('document_date');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('source_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $table->string('supplier_invoice_number', 80)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->unsignedBigInteger('production_order_id')->nullable()->index(); // Module 6 integration point
            $table->foreignId('original_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->foreignId('reason_code_id')->nullable()->constrained('inventory_reason_codes')->restrictOnDelete();
            $table->boolean('requires_handover')->default(true); // transfers
            $table->string('decision', 20)->nullable(); // QC disposition: release | hold | reject
            $table->text('remarks')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'status']);
            $table->index(['factory_id', 'document_type']);
        });

        Schema::create('inventory_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('inventory_documents')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            // As entered + conversion used; base quantity is authoritative.
            $table->decimal('quantity', 20, 6);
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('conversion_factor', 30, 12);
            $table->decimal('base_quantity', 20, 6);
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('source_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('system_base_quantity', 20, 6)->nullable(); // adjustments: snapshot
            $table->decimal('received_base_quantity', 20, 6)->default(0); // transfers: received so far
            $table->string('condition', 20)->nullable(); // returns: good | damaged
            $table->foreignId('reason_code_id')->nullable()->constrained('inventory_reason_codes')->restrictOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index('item_id');
        });

        Schema::create('inventory_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('inventory_documents')->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->json('details')->nullable();
            $table->string('remarks', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_document_events');
        Schema::dropIfExists('inventory_document_lines');
        Schema::dropIfExists('inventory_documents');
        Schema::dropIfExists('inventory_reason_codes');
    }
};
