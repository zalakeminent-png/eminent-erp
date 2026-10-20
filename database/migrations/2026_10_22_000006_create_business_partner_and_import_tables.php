<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 200);
            // Roles are independent flags: a party can be both.
            $table->boolean('is_customer')->default(false)->index();
            $table->boolean('is_supplier')->default(false)->index();
            $table->string('contact_person', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->char('currency', 3)->default('INR');
            $table->string('payment_terms', 150)->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('name');
            $table->index('gstin');
        });

        Schema::create('business_partner_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('business_partners')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('designation', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('business_partner_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('business_partners')->cascadeOnDelete();
            $table->string('address_type', 10); // billing | shipping
            $table->string('label', 100)->nullable();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('country', 100)->default('India');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['partner_id', 'address_type']);
        });

        // Excel/CSV import audit trail.
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('entity', 30);
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('status', 20)->default('uploaded'); // uploaded | validated | processing | completed | failed
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->boolean('update_existing')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('create_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('row_results')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'entity']);
        });

        // Records one-time seed operations so re-seeding never re-applies them.
        Schema::create('seed_markers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->timestamp('applied_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_markers');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('business_partner_addresses');
        Schema::dropIfExists('business_partner_contacts');
        Schema::dropIfExists('business_partners');
    }
};
