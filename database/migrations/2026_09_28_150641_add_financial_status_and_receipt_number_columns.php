<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('financial_status')->default('unpaid');
        });

        DB::table('invoices')->update([
            'financial_status' => DB::raw("CASE WHEN balance_due <= 0 THEN 'paid' WHEN amount_paid > 0 THEN 'partially_paid' ELSE 'unpaid' END"),
        ]);

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('receipt_number')->nullable();
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->unique(['company_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'receipt_number']);
            $table->dropColumn(['receipt_number', 'balance_after']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('financial_status');
        });
    }
};
