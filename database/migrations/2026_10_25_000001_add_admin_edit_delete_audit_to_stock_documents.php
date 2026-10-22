<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit columns for Admin edits/deletions of stock entries. Deleted posted
 * entries are soft-deleted: their stock effect is reversed in the ledger and
 * they disappear from lists, but the record and ledger history remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->unsignedInteger('edit_count')->default(0)->after('cancel_reason');
            $table->foreignId('edited_by')->nullable()->after('edit_count')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('edited_by');
            $table->foreignId('deleted_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();
            $table->string('delete_reason', 500)->nullable()->after('deleted_by');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['edit_count', 'edited_at', 'delete_reason', 'deleted_at']);
        });
    }
};
