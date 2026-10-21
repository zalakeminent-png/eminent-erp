<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive extension of the Module 2 location master: what a warehouse /
 * location is used for in inventory (raw material, WIP, QC pending …).
 * A location without a purpose inherits its warehouse's purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('stock_purpose', 30)->nullable()->after('type_id');
        });
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->string('stock_purpose', 30)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_locations', fn (Blueprint $table) => $table->dropColumn('stock_purpose'));
        Schema::table('warehouses', fn (Blueprint $table) => $table->dropColumn('stock_purpose'));
    }
};
