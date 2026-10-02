<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cash_movements', 'bank_transactions', 'payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('idempotency_key')->nullable();
                $table->unique(['company_id', 'idempotency_key']);
            });
        }
    }

    public function down(): void
    {
        foreach (['cash_movements', 'bank_transactions', 'payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique([$tableName.'_company_id_idempotency_key_unique']);
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
