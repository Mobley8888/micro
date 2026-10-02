<?php

namespace App\Services\Commercial\Stock\Valuation;

use App\Support\StockQuantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class AverageCostValuationStrategy implements StockValuationStrategy
{
    public function allocate(Collection $lots, int $quantityMilli, int $stockQuantityMilli, int $stockValueCents): array
    {
        if ($stockQuantityMilli < $quantityMilli || $stockQuantityMilli <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Le stock disponible est insuffisant.']);
        }
        $averageUnitCost = StockQuantity::averageCostScale($stockValueCents, $stockQuantityMilli);
        $remaining = $quantityMilli;
        $allocated = [];
        foreach ($lots as $lot) {
            $lotQuantity = StockQuantity::toMilli($lot->remaining_quantity);
            if ($lotQuantity <= 0) {
                continue;
            }
            $quantity = min($remaining, $lotQuantity);
            $allocated[] = ['lot_id' => $lot->id, 'quantity_milli' => $quantity];
            $remaining -= $quantity;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining > 0) {
            throw ValidationException::withMessages(['quantity' => 'Le stock disponible est insuffisant.']);
        }

        $totalCost = StockQuantity::lineValueCents($quantityMilli, $averageUnitCost);
        $allocations = [];
        $costAllocated = 0;
        foreach ($allocated as $index => $item) {
            $last = $index === array_key_last($allocated);
            $partCost = $last ? $totalCost - $costAllocated : StockQuantity::lineValueCents($item['quantity_milli'], $averageUnitCost);
            $allocations[] = [
                'lot_id' => $item['lot_id'],
                'quantity_milli' => $item['quantity_milli'],
                'unit_cost_scaled' => $averageUnitCost,
                'total_cost_cents' => $partCost,
            ];
            $costAllocated += $partCost;
        }

        return $allocations;
    }

    public function name(): string
    {
        return 'CMUP';
    }
}
