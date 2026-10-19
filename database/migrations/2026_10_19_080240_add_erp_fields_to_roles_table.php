<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('guard_name');
            // Core roles are seeded and cannot be deleted through the API.
            $table->boolean('is_system')->default(false)->after('description');
            $table->boolean('is_active')->default(true)->index()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['description', 'is_system', 'is_active']);
        });
    }
};
