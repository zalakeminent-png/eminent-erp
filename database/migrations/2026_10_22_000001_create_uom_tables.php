<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Universal unit catalogue (not company specific).
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 60);
            $table->string('dimension', 20)->index();
            $table->unsignedTinyInteger('decimal_precision')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Universal conversions: 1 from_uom = factor × to_uom (same dimension only).
        Schema::create('uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('factor', 30, 12);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['from_uom_id', 'to_uom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uom_conversions');
        Schema::dropIfExists('units_of_measure');
    }
};
