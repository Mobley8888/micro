<?php

namespace App\Services\Commercial\Stock\Valuation;

use Illuminate\Database\Eloquent\Collection;

interface StockValuationStrategy
{
    /** @return list<array{lot_id:string,quantity_milli:int,unit_cost_scaled:int,total_cost_cents:int}> */
    public function allocate(Collection $lots, int $quantityMilli, int $stockQuantityMilli, int $stockValueCents): array;

    public function name(): string;
}
