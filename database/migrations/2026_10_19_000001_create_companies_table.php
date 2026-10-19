<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('code', 20)->unique();
            $table->string('logo_path')->nullable();
            $table->string('gstin', 15)->nullable()->unique();
            $table->string('pan', 10)->nullable();
            $table->string('cin', 21)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('country', 100)->default('India');
            $table->char('currency', 3)->default('INR');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->unsignedTinyInteger('financial_year_start_month')->default(4);
            $table->unsignedTinyInteger('financial_year_end_month')->default(3);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
