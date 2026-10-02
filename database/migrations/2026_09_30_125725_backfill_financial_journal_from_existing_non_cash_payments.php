<?php

use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.status', 'posted')
            ->whereNull('payments.cash_session_id')
            ->whereNull('payments.bank_account_id')
            ->where('payment_methods.code', '!=', 'cash')
            ->select('payments.*')
            ->orderBy('payments.id')
            ->chunk(500, function ($payments): void {
                foreach ($payments as $payment) {
                    DB::table('financial_journal_entries')->insertOrIgnore([
                        'id' => (string) Str::uuid(), 'company_id' => $payment->company_id,
                        'account_type' => 'payment_method', 'account_id' => $payment->payment_method_id,
                        'direction' => 'in', 'amount' => $payment->amount, 'occurred_at' => $payment->paid_at,
                        'source_type' => Payment::class, 'source_id' => $payment->id,
                        'reference' => $payment->reference, 'description' => 'Encaissement '.$payment->number,
                        'created_by' => $payment->received_by, 'created_at' => $payment->created_at,
                        'updated_at' => $payment->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Preserve financial audit history if this data migration is rolled back.
    }
};
