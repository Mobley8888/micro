<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'warehouse_id', 'product_id', 'supplier_id', 'purchase_receipt_item_id', 'origin_lot_id', 'lot_number', 'received_at', 'expires_at', 'initial_quantity', 'remaining_quantity', 'unit_cost', 'initial_value', 'remaining_value', 'status'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'expires_at' => 'date', 'initial_quantity' => 'decimal:3', 'remaining_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'initial_value' => 'decimal:2', 'remaining_value' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptItem::class, 'purchase_receipt_item_id');
    }

    public function originLot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_lot_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockMovementAllocation::class);
    }
}
