<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('material_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('family_id')->constrained('material_families')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('standard', 150)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('family_id');
        });

        Schema::create('manufacturing_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('process_type', 20)->default('internal'); // internal | external
            $table->foreignId('default_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->boolean('requires_qc')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained('factories')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('machine_type', 80)->nullable();
            $table->string('workstation', 100)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            // Capacity: capacity_qty [capacity_uom] per capacity_period of capacity_period_hours.
            $table->decimal('capacity_qty', 18, 4)->nullable();
            $table->foreignId('capacity_uom_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->string('capacity_period', 10)->nullable(); // hour | shift | day
            $table->decimal('capacity_period_hours', 5, 2)->nullable();
            $table->decimal('working_hours_per_day', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['factory_id', 'code']);
            $table->index('department_id');
        });

        Schema::create('machine_process', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('manufacturing_processes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['machine_id', 'process_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_process');
        Schema::dropIfExists('machines');
        Schema::dropIfExists('manufacturing_processes');
        Schema::dropIfExists('material_grades');
        Schema::dropIfExists('material_families');
    }
};
