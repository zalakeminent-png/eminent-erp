<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable stock ledger (authoritative) + cached balances.
 * Quantities are signed decimals in the item's base UOM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('transaction_number', 40)->unique();
            $table->string('transaction_type', 40)->index();
            // Unique: a repeated posting with the same key returns the original.
            $table->string('idempotency_key', 120)->unique();
            $table->string('source_document_type', 40)->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('inventory_transactions')->restrictOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_document_type', 'source_document_id']);
        });

        Schema::create('inventory_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('stock_status', 20);
            $table->decimal('quantity', 20, 6); // signed, base UOM
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('balance_after', 20, 6); // running balance of this stock identity
            $table->decimal('unit_cost', 20, 6)->nullable();
            $table->decimal('value_delta', 20, 4)->nullable();
            // Where stock came from / went to outside the location network.
            $table->string('counterparty', 30)->nullable(); // supplier | opening | adjustment | count | consumption …
            $table->string('source_document_type', 40)->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->unsignedBigInteger('source_document_line_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_id', 'location_id', 'stock_status', 'id'], 'ile_identity_idx');
            $table->index(['posted_at', 'id']);
            $table->index(['warehouse_id', 'posted_at']);
            $table->index(['source_document_type', 'source_document_id'], 'ile_source_idx');
        });

        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('stock_status', 20);
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->default(0);
            // Null = valuation unknown (some quantity was received without cost).
            $table->decimal('value', 20, 4)->nullable()->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamp('last_receipt_at')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'location_id', 'stock_status'], 'inv_balance_identity_unique');
            $table->index(['warehouse_id', 'stock_status']);
            $table->index(['location_id', 'stock_status']);
        });

        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('reservation_number', 40)->unique();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('quantity', 20, 6); // entered
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('conversion_factor', 30, 12);
            $table->decimal('base_quantity', 20, 6);
            $table->decimal('released_base_quantity', 20, 6)->default(0);
            $table->foreignId('base_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            // Future: sales_order / production_order / dispatch. Manual for now.
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->date('expires_on')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('inventory_reservation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('inventory_reservations')->cascadeOnDelete();
            $table->string('action', 20); // created | released | cancelled | expired
            $table->decimal('base_quantity', 20, 6)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->immutableLedger();
    }

    /** Database-level guard: ledger rows can never be updated or deleted. */
    private function immutableLedger(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION inventory_ledger_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Inventory ledger entries and transactions are immutable; post a reversal instead.';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER inventory_ledger_entries_immutable BEFORE UPDATE OR DELETE ON inventory_ledger_entries
                    FOR EACH ROW EXECUTE FUNCTION inventory_ledger_immutable();
                CREATE TRIGGER inventory_transactions_immutable BEFORE UPDATE OR DELETE ON inventory_transactions
                    FOR EACH ROW EXECUTE FUNCTION inventory_ledger_immutable();
            SQL);
        } elseif ($driver === 'sqlite') {
            foreach (['inventory_ledger_entries', 'inventory_transactions'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($op)." BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, 'Inventory ledger is immutable'); END;");
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS inventory_ledger_entries_immutable ON inventory_ledger_entries; DROP TRIGGER IF EXISTS inventory_transactions_immutable ON inventory_transactions; DROP FUNCTION IF EXISTS inventory_ledger_immutable();');
        }
        Schema::dropIfExists('inventory_reservation_events');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_ledger_entries');
        Schema::dropIfExists('inventory_transactions');
    }
};
