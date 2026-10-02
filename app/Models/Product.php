<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['company_id', 'tax_id', 'type', 'code', 'name', 'description', 'sale_price', 'purchase_price', 'is_stockable', 'stock_minimum', 'stock_maximum', 'reorder_level', 'is_active'];

    protected function casts(): array
    {
        return ['sale_price' => 'decimal:2', 'purchase_price' => 'decimal:4', 'stock_minimum' => 'decimal:3', 'stock_maximum' => 'decimal:3', 'reorder_level' => 'decimal:3', 'is_stockable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function stockDisplayLabel(): string
    {
        if (! $this->is_stockable) {
            return 'Non stockable';
        }

        $quantity = $this->currentStockQuantity();
        if ($quantity === null) {
            return 'Stock non initialisé';
        }

        $value = $this->formatStockMetric($quantity);
        $unit = $quantity <= 1 ? 'unité' : 'unités';

        return $value.' '.$unit;
    }

    public function formatStockMetric(float|string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $numeric = (float) $value;
        if (floor($numeric) === $numeric) {
            return number_format($numeric, 0, ',', ' ');
        }

        $formatted = number_format($numeric, 3, ',', ' ');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    public function stockState(): string
    {
        if (! $this->is_stockable) {
            return 'NON STOCKABLE';
        }

        $quantity = $this->currentStockQuantity();
        if ($quantity === null) {
            return 'NON INITIALISÉ';
        }

        if ($quantity <= 0) {
            return 'RUPTURE';
        }

        if ($this->reorder_level !== null && $quantity <= (float) $this->reorder_level) {
            return 'RÉAPPROVISIONNEMENT';
        }

        if ($this->stock_minimum !== null && $quantity <= (float) $this->stock_minimum) {
            return 'À SURVEILLER';
        }

        return 'NORMAL';
    }

    public function currentStockQuantity(): ?float
    {
        if (! $this->is_stockable) {
            return null;
        }

        if (! $this->stockLots()->exists()) {
            return null;
        }

        $quantity = $this->stockLots()->sum('remaining_quantity');
        if ($quantity === null || $quantity === '') {
            return null;
        }

        return (float) $quantity;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function quoteItems(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
