<?php

namespace App\Models;

use App\Support\CashAmount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'name', 'bank_name', 'account_number', 'account_type', 'currency', 'opening_balance', 'is_active', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function balance(): string
    {
        $cents = CashAmount::toCents($this->opening_balance);
        foreach ($this->transactions()->where('status', 'posted')->get(['amount', 'direction']) as $transaction) {
            $cents += CashAmount::toCents($transaction->amount) * ($transaction->direction === 'in' ? 1 : -1);
        }

        return CashAmount::fromCents($cents);
    }

    public function maskedAccountNumber(): ?string
    {
        return $this->account_number ? '••••'.substr($this->account_number, -4) : null;
    }
}
