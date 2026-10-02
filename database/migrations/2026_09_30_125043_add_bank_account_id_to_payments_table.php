<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUuid('bank_account_id')->nullable()->after('cash_session_id')->constrained()->restrictOnDelete();
            $table->index(['company_id', 'bank_account_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'bank_account_id', 'paid_at']);
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
