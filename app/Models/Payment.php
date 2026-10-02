<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'invoice_id', 'customer_id', 'payment_method_id', 'cash_session_id', 'bank_account_id', 'received_by', 'number', 'receipt_number', 'balance_after', 'idempotency_key', 'amount', 'paid_at', 'reference', 'status', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (Payment $payment): void {
            if ($payment->status !== 'posted' || $payment->cash_session_id !== null || $payment->bank_account_id !== null) {
                return;
            }
            FinancialJournalEntry::query()->firstOrCreate([
                'company_id' => $payment->company_id,
                'account_type' => 'payment_method',
                'account_id' => $payment->payment_method_id,
                'source_type' => self::class,
                'source_id' => $payment->id,
            ], [
                'direction' => 'in', 'amount' => $payment->amount, 'occurred_at' => $payment->paid_at,
                'reference' => $payment->reference, 'description' => 'Encaissement '.$payment->number,
                'created_by' => $payment->received_by,
            ]);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
