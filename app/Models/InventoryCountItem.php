<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountItem extends Model
{
    use HasUuids;

    protected $fillable = ['inventory_count_id', 'product_id', 'expected_quantity', 'counted_quantity', 'difference_quantity', 'unit_cost', 'difference_value', 'notes'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3', 'difference_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'difference_value' => 'decimal:2'];
    }

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
