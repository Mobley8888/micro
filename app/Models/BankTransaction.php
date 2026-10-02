<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'bank_account_id', 'type', 'direction', 'amount', 'transaction_date', 'reference', 'description', 'counterparty', 'status', 'source_type', 'source_id', 'reconciled_at', 'reconciled_by', 'created_by', 'notes', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transaction_date' => 'datetime', 'reconciled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (BankTransaction $transaction): void {
            FinancialJournalEntry::query()->firstOrCreate([
                'company_id' => $transaction->company_id,
                'account_type' => 'bank',
                'account_id' => $transaction->bank_account_id,
                'source_type' => self::class,
                'source_id' => $transaction->id,
            ], [
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
                'occurred_at' => $transaction->transaction_date,
                'reference' => $transaction->reference,
                'description' => $transaction->description,
                'created_by' => $transaction->created_by,
            ]);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
