<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'customer_id', 'quote_id', 'number', 'issue_date', 'due_date', 'subtotal', 'discount_amount', 'tax_amount', 'total', 'amount_paid', 'balance_due', 'status', 'notes'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance_due' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receivable(): HasOne
    {
        return $this->hasOne(Receivable::class);
    }

    public function financialStatusLabel(): string
    {
        return match ($this->financial_status) {
            'partially_paid' => 'Partiellement payée',
            'paid' => 'Payée',
            'cancelled' => 'Annulée',
            default => 'Impayée',
        };
    }

    public function daysOverdue(): int
    {
        if ((float) $this->balance_due <= 0 || $this->due_date === null || $this->due_date->isToday() || $this->due_date->isFuture()) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(now()->startOfDay());
    }
}
