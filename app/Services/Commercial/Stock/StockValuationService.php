<?php

namespace App\Services\Commercial\Stock;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockLot;
use App\Services\Commercial\Stock\Valuation\AverageCostValuationStrategy;
use App\Services\Commercial\Stock\Valuation\FifoValuationStrategy;
use App\Services\Commercial\Stock\Valuation\StockValuationStrategy;
use App\Support\CashAmount;
use App\Support\StockQuantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class StockValuationService
{
    public function __construct(
        private readonly FifoValuationStrategy $fifo,
        private readonly AverageCostValuationStrategy $averageCost,
    ) {}

    public function method(Company $company): string
    {
        return strtoupper($company->valuation_method) === 'CMUP' ? 'CMUP' : 'FIFO';
    }

    public function strategy(Company $company): StockValuationStrategy
    {
        return $this->method($company) === 'CMUP' ? $this->averageCost : $this->fifo;
    }

    /** @return array{quantity_milli:int,value_cents:int,unit_cost_scaled:int} */
    public function balance(Product $product, ?string $warehouseId = null): array
    {
        $lots = StockLot::query()->where('company_id', $product->company_id)->where('product_id', $product->id)
            ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))->get(['remaining_quantity', 'remaining_value']);
        $quantityMilli = 0;
        $valueCents = 0;
        foreach ($lots as $lot) {
            $quantityMilli += StockQuantity::toSignedMilli($lot->remaining_quantity);
            $valueCents += CashAmount::toCents($lot->remaining_value);
        }
        if ($quantityMilli < 0) {
            $unitCostScaled = 0;
        } else {
            $unitCostScaled = StockQuantity::averageCostScale(max(0, $valueCents), $quantityMilli);
        }

        return ['quantity_milli' => $quantityMilli, 'value_cents' => $valueCents, 'unit_cost_scaled' => $unitCostScaled];
    }

    /** @param Collection<int, StockLot> $lots
     * @return list<array{lot_id:string,quantity_milli:int,unit_cost_scaled:int,total_cost_cents:int}>
     */
    public function allocate(Company $company, Collection $lots, int $quantityMilli, int $stockQuantityMilli, int $stockValueCents): array
    {
        if ($quantityMilli <= 0 || $stockQuantityMilli < $quantityMilli || $stockValueCents < 0) {
            throw ValidationException::withMessages(['quantity' => 'La quantité demandée dépasse le stock disponible.']);
        }

        return $this->strategy($company)->allocate($lots, $quantityMilli, $stockQuantityMilli, $stockValueCents);
    }
}
