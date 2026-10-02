<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'code', 'name', 'trade_name', 'contact_name', 'phone', 'email', 'address', 'city', 'country', 'tax_number', 'registration_number', 'payment_terms', 'payment_delay_days', 'currency', 'is_active', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_delay_days' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function purchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }
}
