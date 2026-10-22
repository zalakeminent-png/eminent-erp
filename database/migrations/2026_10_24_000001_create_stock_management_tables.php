<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock management: SKU Master, Vendor Master (+ vendor/SKU mapping),
 * stock documents (inward / outward / opening) and an append-only stock
 * ledger with cached balances per SKU and storage location. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('sku', 60);
            $table->string('product_name', 255);
            $table->string('category', 80)->nullable()->index();
            $table->string('material', 80)->nullable()->index();
            $table->string('size', 80)->nullable();
            $table->string('unit', 20)->nullable(); // e.g. PCS, KG — informational
            $table->decimal('reorder_level', 16, 3)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('city', 80)->nullable()->index();
            $table->string('address', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('vendor_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('remarks', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'sku_id']);
            $table->index('sku_id');
        });

        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('type', 12)->index(); // inward | outward | opening
            $table->string('number', 30)->unique();
            $table->date('document_date')->index();
            $table->string('status', 12)->default('draft')->index(); // draft | posted | reversed | cancelled
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            // inward: received / rejected (accepted = received − rejected); outward & opening: quantity
            $table->decimal('quantity', 16, 3)->default(0);
            $table->decimal('received_qty', 16, 3)->default(0);
            $table->decimal('rejected_qty', 16, 3)->default(0);
            $table->string('recipient', 150)->nullable();
            $table->string('job_no', 60)->nullable();
            $table->string('reference', 150)->nullable(); // invoice / challan / purpose
            $table->text('remarks')->nullable();
            $table->boolean('vendor_mapped')->nullable();
            $table->string('vendor_check_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['sku_id', 'location_id']);
        });

        // Append-only: posting adds rows, reversal adds negating rows.
        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('stock_documents')->restrictOnDelete();
            $table->string('kind', 12); // opening | inward | outward
            $table->boolean('is_reversal')->default(false);
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->decimal('qty_in', 16, 3)->default(0);
            $table->decimal('qty_rejected', 16, 3)->default(0);
            $table->decimal('qty_out', 16, 3)->default(0);
            $table->decimal('balance_after', 16, 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['sku_id', 'location_id']);
            $table->index(['factory_id', 'sku_id']);
        });

        // Cached live balance per SKU + storage location (row-locked when posting).
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sku_id')->constrained('skus')->restrictOnDelete();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->decimal('quantity', 16, 3)->default(0);
            $table->timestamps();

            $table->unique(['sku_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_ledger_entries');
        Schema::dropIfExists('stock_documents');
        Schema::dropIfExists('vendor_skus');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('skus');
    }
};
