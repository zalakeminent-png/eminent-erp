<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained('companies')->restrictOnDelete();
            $table->string('employee_code', 30)->nullable()->unique()->after('company_id');
            $table->string('first_name', 100)->default('')->after('employee_code');
            $table->string('last_name', 100)->default('')->after('first_name');
            $table->string('mobile', 20)->nullable()->after('email');
            // Department FK is added by Module 2 (departments table).
            $table->unsignedBigInteger('department_id')->nullable()->index()->after('mobile');
            $table->boolean('is_active')->default(true)->index()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->timestamp('deactivated_at')->nullable()->after('last_login_at');
            $table->index(['last_name', 'first_name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_name', 'first_name']);
            $table->dropConstrainedForeignId('company_id');
            $table->dropUnique(['employee_code']);
            $table->dropIndex(['department_id']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'employee_code', 'first_name', 'last_name', 'mobile', 'department_id',
                'is_active', 'last_login_at', 'deactivated_at',
            ]);
        });
    }
};
