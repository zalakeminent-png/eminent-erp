<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Upload → validate → draft → submitted → approved → posted (→ reversed)
        Schema::create('opening_stock_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('import_number', 40)->unique();
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('status', 20)->default('uploaded')->index();
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->foreignId('transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('reversal_transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->text('failure_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('opening_stock_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('opening_stock_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->string('status', 10); // accepted | rejected
            $table->json('errors')->nullable();
            $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('stock_status', 20)->nullable();
            $table->decimal('quantity', 20, 6)->nullable();
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('conversion_factor', 30, 12)->nullable();
            $table->decimal('base_quantity', 20, 6)->nullable();
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_id', 'status']);
        });

        // draft → counting (snapshot + location lock) → submitted → approved → posted | cancelled
        Schema::create('stock_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('count_number', 40)->unique();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->json('location_ids')->nullable(); // null = whole warehouse
            $table->json('category_ids')->nullable();
            $table->json('item_ids')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('lock_locations')->default(true);
            $table->timestamp('cutoff_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('stock_count_sessions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('stock_status', 20)->default('available');
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('system_quantity', 20, 6); // snapshot at cutoff
            $table->decimal('counted_quantity', 20, 6)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'item_id', 'location_id', 'stock_status'], 'count_line_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_count_sessions');
        Schema::dropIfExists('opening_stock_import_rows');
        Schema::dropIfExists('opening_stock_imports');
    }
};
