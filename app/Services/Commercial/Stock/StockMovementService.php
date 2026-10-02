<?php

namespace App\Services\Commercial\Stock;

use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseReceiptItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockMovementAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CashAmount;
use App\Support\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockMovementService
{
    public function __construct(private readonly StockValuationService $valuationService) {}

    public function defaultWarehouse(User $user, string $companyId): Warehouse
    {
        abort_unless($user->isSuperAdmin() || $user->company_id === $companyId, 404);

        return DB::transaction(function () use ($user, $companyId): Warehouse {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            return Warehouse::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => 'MAIN'],
                ['name' => 'Dépôt principal', 'is_default' => true, 'is_active' => true, 'created_by' => $user->id],
            );
        }, attempts: 3);
    }

    public function issue(User $user, Product $product, string $quantity, Warehouse $warehouse, string $type, ?string $reference = null, ?string $sourceType = null, ?string $sourceId = null, mixed $occurredAt = null, ?string $idempotencyKey = null, ?string $reason = null): StockMovement
    {
        abort_unless($user->isSuperAdmin() || $user->company_id === $product->company_id, 404);

        return DB::transaction(function () use ($user, $product, $quantity, $warehouse, $type, $reference, $sourceType, $sourceId, $occurredAt, $idempotencyKey, $reason): StockMovement {
            $company = Company::query()->whereKey($product->company_id)->lockForUpdate()->firstOrFail();
            $lockedProduct = Product::withTrashed()->whereKey($product->id)->where('company_id', $company->id)->where('type', 'product')->where('is_stockable', true)->lockForUpdate()->firstOrFail();
            $idempotencyKey ??= (string) Str::uuid();
            $existing = StockMovement::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
            $quantityMilli = StockQuantity::toMilli($quantity);
            if ($quantityMilli <= 0) {
                throw ValidationException::withMessages(['quantity' => 'La quantité doit être supérieure à zéro.']);
            }
            $lots = StockLot::query()->where('company_id', $company->id)->where('warehouse_id', $warehouse->id)
                ->where('product_id', $lockedProduct->id)->where('remaining_quantity', '>', 0)->orderBy('received_at')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $balance = $this->valuationService->balance($lockedProduct, $warehouse->id);
            if ($balance['quantity_milli'] < $quantityMilli && ! $company->allow_negative_stock) {
                throw ValidationException::withMessages(['quantity' => 'Le stock disponible est insuffisant pour cette sortie.']);
            }
            $coveredQuantity = min($quantityMilli, max(0, $balance['quantity_milli']));
            $allocations = $coveredQuantity > 0
                ? $this->valuationService->allocate($company, $lots, $coveredQuantity, $balance['quantity_milli'], max(0, $balance['value_cents']))
                : [];
            $coveredCost = array_sum(array_column($allocations, 'total_cost_cents'));
            $shortage = $quantityMilli - $coveredQuantity;
            if ($shortage > 0) {
                $fallbackCost = $lots->first()?->unit_cost ?? $lockedProduct->purchase_price ?? '0.0000';
                $unitCostScaled = StockQuantity::costToScale((string) $fallbackCost);
                $shortageCost = StockQuantity::lineValueCents($shortage, $unitCostScaled);
                $negativeLot = StockLot::query()->create([
                    'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $lockedProduct->id,
                    'lot_number' => 'NEG-'.$idempotencyKey, 'received_at' => $occurredAt ?? now(), 'initial_quantity' => '0.000',
                    'remaining_quantity' => StockQuantity::fromMilli(-$shortage), 'unit_cost' => StockQuantity::costFromScale($unitCostScaled),
                    'initial_value' => '0.00', 'remaining_value' => CashAmount::fromCents(-$shortageCost), 'status' => 'negative',
                ]);
                $allocations[] = ['lot_id' => $negativeLot->id, 'quantity_milli' => $shortage, 'unit_cost_scaled' => $unitCostScaled, 'total_cost_cents' => $shortageCost];
            }
            $totalCost = $coveredCost + ($shortage > 0 ? $allocations[array_key_last($allocations)]['total_cost_cents'] : 0);
            $method = $this->valuationService->method($company);
            foreach ($allocations as $allocation) {
                $lot = StockLot::query()->whereKey($allocation['lot_id'])->lockForUpdate()->firstOrFail();
                if ($lot->status === 'negative') {
                    continue;
                }
                $newQuantity = StockQuantity::toMilli($lot->remaining_quantity) - $allocation['quantity_milli'];
                $lot->remaining_quantity = StockQuantity::fromMilli($newQuantity);
                if ($method === 'FIFO') {
                    $lot->remaining_value = CashAmount::fromCents(CashAmount::toCents($lot->remaining_value) - $allocation['total_cost_cents']);
                }
                $lot->status = $newQuantity === 0 ? 'depleted' : 'available';
                $lot->save();
            }
            if ($method === 'CMUP' && $shortage === 0) {
                $this->redistributeAverageValue($company->id, $lockedProduct->id, $warehouse->id, $balance['value_cents'] - $totalCost);
            }
            $movement = StockMovement::query()->create([
                'company_id' => $company->id, 'warehouse_id' => $warehouse->id, 'product_id' => $lockedProduct->id,
                'type' => $type, 'direction' => 'out', 'quantity' => StockQuantity::fromMilli($quantityMilli),
                'unit_cost' => StockQuantity::costFromScale(StockQuantity::averageCostScale($totalCost, $quantityMilli)),
                'total_cost' => CashAmount::fromCents($totalCost), 'occurred_at' => $occurredAt ?? now(),
                'reference' => $reference, 'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $user->id,
                'metadata' => array_filter(['valuation_method' => $method, 'reason' => $reason]), 'idempotency_key' => $idempotencyKey,
            ]);
            foreach ($allocations as $allocation) {
                StockMovementAllocation::query()->create([
                    'stock_movement_id' => $movement->id, 'stock_lot_id' => $allocation['lot_id'],
                    'quantity' => StockQuantity::fromMilli($allocation['quantity_milli']),
                    'unit_cost' => StockQuantity::costFromScale($allocation['unit_cost_scaled']),
                    'total_cost' => CashAmount::fromCents($allocation['total_cost_cents']),
                ]);
            }

            return $movement->load('allocations');
        }, attempts: 3);
    }

    public function createInboundLot(string $companyId, Warehouse $warehouse, Product $product, string $quantity, string $unitCost, string $lotNumber, mixed $receivedAt, ?string $expiresAt, ?string $supplierId, ?PurchaseReceiptItem $receiptItem, User $actor, string $type, ?string $reference = null, ?string $sourceType = null, ?string $sourceId = null, ?string $idempotencyKey = null, ?string $originLotId = null, ?string $reason = null): StockMovement
    {
        abort_unless($warehouse->company_id === $companyId && $product->company_id === $companyId && $warehouse->is_active, 404);
        $quantityMilli = StockQuantity::toMilli($quantity);
        $unitCostScaled = StockQuantity::costToScale($unitCost);
        if ($quantityMilli <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La quantité reçue doit être supérieure à zéro.']);
        }
        $totalCents = StockQuantity::lineValueCents($quantityMilli, $unitCostScaled);
        $existing = $idempotencyKey ? StockMovement::query()->where('company_id', $companyId)->where('idempotency_key', $idempotencyKey)->first() : null;
        if ($existing !== null) {
            return $existing;
        }
        $lot = StockLot::query()->create([
            'company_id' => $companyId, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'supplier_id' => $supplierId, 'purchase_receipt_item_id' => $receiptItem?->id, 'origin_lot_id' => $originLotId,
            'lot_number' => $lotNumber, 'received_at' => $receivedAt, 'expires_at' => $expiresAt,
            'initial_quantity' => StockQuantity::fromMilli($quantityMilli), 'remaining_quantity' => StockQuantity::fromMilli($quantityMilli),
            'unit_cost' => StockQuantity::costFromScale($unitCostScaled), 'initial_value' => CashAmount::fromCents($totalCents),
            'remaining_value' => CashAmount::fromCents($totalCents), 'status' => 'available',
        ]);
        $movement = StockMovement::query()->create([
            'company_id' => $companyId, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'stock_lot_id' => $lot->id, 'type' => $type, 'direction' => 'in',
            'quantity' => StockQuantity::fromMilli($quantityMilli), 'unit_cost' => StockQuantity::costFromScale($unitCostScaled),
            'total_cost' => CashAmount::fromCents($totalCents), 'occurred_at' => $receivedAt, 'reference' => $reference,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $actor->id,
            'metadata' => $reason !== null ? ['reason' => $reason] : null,
            'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
        ]);
        if ($receiptItem !== null) {
            $receiptItem->forceFill(['stock_lot_id' => $lot->id])->save();
        }
        $product->forceFill(['purchase_price' => StockQuantity::costFromScale($unitCostScaled)])->save();

        return $movement;
    }

    private function redistributeAverageValue(string $companyId, string $productId, string $warehouseId, int $valueCents): void
    {
        $lots = StockLot::query()->where('company_id', $companyId)->where('product_id', $productId)->where('warehouse_id', $warehouseId)->where('remaining_quantity', '>', 0)->orderBy('id')->lockForUpdate()->get();
        $quantityTotal = $lots->sum(fn (StockLot $lot): int => StockQuantity::toMilli($lot->remaining_quantity));
        $remainingValue = $valueCents;
        foreach ($lots->values() as $index => $lot) {
            $quantity = StockQuantity::toMilli($lot->remaining_quantity);
            $value = $index === $lots->count() - 1 || $quantityTotal === 0
                ? $remainingValue
                : intdiv($valueCents * $quantity + intdiv($quantityTotal, 2), $quantityTotal);
            $remainingValue -= $value;
            $lot->remaining_value = CashAmount::fromCents($value);
            $lot->save();
        }
    }
}
