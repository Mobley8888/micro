<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'expense.view' => 'Consulter les dépenses', 'expense.create' => 'Créer une dépense',
        'expense.cancel' => 'Annuler une dépense', 'cash.expense' => 'Enregistrer une dépense en caisse',
        'cash.outflow' => 'Enregistrer une sortie de caisse', 'bank.view' => 'Consulter les banques',
        'bank.create' => 'Gérer les comptes bancaires', 'bank.transaction' => 'Enregistrer une transaction bancaire',
        'bank.reconcile' => 'Rapprocher les transactions bancaires', 'financial.journal.view' => 'Consulter le journal financier',
        'financial.report.view' => 'Consulter les rapports financiers',
    ];

    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_type')->default('current');
            $table->string('currency', 3)->default('XAF');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });
        Schema::create('expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('supplier')->nullable();
            $table->string('reference')->nullable();
            $table->text('description');
            $table->decimal('amount', 18, 2);
            $table->date('expense_date');
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('posted');
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'expense_date', 'status']);
            $table->index(['cash_session_id', 'expense_date']);
            $table->index(['bank_account_id', 'expense_date']);
        });
        Schema::create('financial_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('from_type');
            $table->uuid('from_id');
            $table->string('to_type');
            $table->uuid('to_id');
            $table->decimal('amount', 18, 2);
            $table->timestamp('transferred_at');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'transferred_at']);
        });
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('bank_account_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('direction');
            $table->decimal('amount', 18, 2);
            $table->timestamp('transaction_date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('counterparty')->nullable();
            $table->string('status')->default('posted');
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_type', 'source_id'], 'bank_transactions_source_unique');
            $table->index(['company_id', 'transaction_date', 'status']);
            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['company_id', 'reconciled_at']);
        });
        Schema::create('financial_journal_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('account_type');
            $table->uuid('account_id');
            $table->string('direction');
            $table->decimal('amount', 18, 2);
            $table->timestamp('occurred_at');
            $table->string('source_type');
            $table->uuid('source_id');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'account_type', 'account_id', 'source_type', 'source_id'], 'financial_journal_source_account_unique');
            $table->index(['company_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['company_id', 'account_type', 'account_id', 'occurred_at'], 'financial_journal_account_date_index');
        });

        $now = now();
        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id', 'slug');
        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            $allowed = match ($role->slug) {
                'super_admin', 'super-administrator', 'company_admin' => array_keys(self::PERMISSIONS),
                'operator' => ['expense.view', 'cash.expense', 'cash.outflow'],
                default => [],
            };
            foreach ($allowed as $slug) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $ids[$slug]]);
            }
        }
    }

    public function down(): void
    {
        foreach (['financial_journal_entries', 'bank_transactions', 'financial_transfers', 'expenses', 'bank_accounts', 'expense_categories'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('permission_role')->whereIn('permission_id', DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->select('id'))->delete();
        DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();
    }
};
