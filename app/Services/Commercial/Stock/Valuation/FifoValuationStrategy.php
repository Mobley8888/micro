<?php

namespace App\Services\Commercial\Stock\Valuation;

use App\Support\StockQuantity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class FifoValuationStrategy implements StockValuationStrategy
{
    public function allocate(Collection $lots, int $quantityMilli, int $stockQuantityMilli, int $stockValueCents): array
    {
        $remaining = $quantityMilli;
        $allocations = [];
        foreach ($lots as $lot) {
            $lotQuantity = StockQuantity::toMilli($lot->remaining_quantity);
            if ($lotQuantity <= 0) {
                continue;
            }
            $allocated = min($remaining, $lotQuantity);
            $unitCost = StockQuantity::costToScale($lot->unit_cost);
            $allocations[] = [
                'lot_id' => $lot->id,
                'quantity_milli' => $allocated,
                'unit_cost_scaled' => $unitCost,
                'total_cost_cents' => StockQuantity::lineValueCents($allocated, $unitCost),
            ];
            $remaining -= $allocated;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining > 0) {
            throw ValidationException::withMessages(['quantity' => 'Le stock disponible est insuffisant.']);
        }

        return $allocations;
    }

    public function name(): string
    {
        return 'FIFO';
    }
}
