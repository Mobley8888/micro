<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransfer extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'from_type', 'from_id', 'to_type', 'to_id', 'amount', 'transferred_at', 'reference', 'description', 'created_by', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transferred_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
