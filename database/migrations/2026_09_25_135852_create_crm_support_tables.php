<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['company_id', 'slug']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('company_id')->constrained('customer_categories')->nullOnDelete();
            $table->string('contact_name')->nullable()->after('registration_number');
        });

        Schema::create('lead_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['company_id', 'slug']);
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('source_id')->nullable()->after('company_id')->constrained('lead_sources')->nullOnDelete();
            $table->date('next_action_at')->nullable()->after('potential_amount');
        });

        Schema::create('lead_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropForeign(['source_id']);
            $table->dropColumn(['source_id', 'next_action_at']);
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'contact_name']);
        });
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('customer_categories');
    }
};
