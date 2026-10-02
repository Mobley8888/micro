<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Product;
use App\Models\PurchaseReceiptItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\Stock\StockMovementService;
use App\Support\StockQuantity;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function __construct(private readonly StockMovementService $stockMovementService) {}

    public function getCurrentStock(Product $product, ?string $warehouseId = null): ?float
    {
        if (! $product->is_stockable) {
            return null;
        }

        $query = StockLot::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id);

        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('warehouse_id', $warehouseId);
        }

        if (! $query->exists()) {
            return null;
        }

        $total = $query->sum('remaining_quantity');
        if ($total === null || $total === '') {
            return null;
        }

        return (float) $total;
    }

    public function getStockByWarehouse(Product $product): array
    {
        if (! $product->is_stockable) {
            return [];
        }

        return StockLot::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->selectRaw('warehouse_id, SUM(remaining_quantity) as total_quantity')
            ->groupBy('warehouse_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->warehouse_id => (float) $row->total_quantity])
            ->all();
    }

    public function recordEntry(
        Product $product,
        string|int|float $quantity,
        ?User $actor = null,
        ?Warehouse $warehouse = null,
        ?string $reference = null,
        ?string $unitCost = null,
        ?string $idempotencyKey = null,
        DateTimeInterface|string|null $occurredAt = null,
        string $type = 'manual_entry',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $supplierId = null,
        ?PurchaseReceiptItem $receiptItem = null,
        ?string $lotNumber = null,
        ?string $expiresAt = null,
        ?string $reason = null,
    ): StockMovement {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw ValidationException::withMessages(['user' => 'Un utilisateur authentifié est requis pour enregistrer un mouvement de stock.']);
        }

        if ($product->type !== 'product' || ! $product->is_stockable) {
            throw ValidationException::withMessages(['product' => 'Seuls les produits stockables peuvent recevoir des mouvements.']);
        }

        $this->assertSameCompany($product, $actor, 'recordEntry');

        $occurredAt ??= now();

        return DB::transaction(function () use ($product, $quantity, $actor, $warehouse, $reference, $unitCost, $idempotencyKey, $occurredAt, $type, $sourceType, $sourceId, $supplierId, $receiptItem, $lotNumber, $expiresAt, $reason): StockMovement {
            Company::query()->whereKey($product->company_id)->lockForUpdate()->firstOrFail();
            $lockedProduct = Product::withTrashed()->whereKey($product->id)->where('company_id', $product->company_id)->lockForUpdate()->firstOrFail();
            if ($lockedProduct->type !== 'product' || ! $lockedProduct->is_stockable) {
                throw ValidationException::withMessages(['product' => 'Seuls les produits stockables peuvent recevoir des mouvements.']);
            }

            $resolvedWarehouse = $warehouse ?? $this->resolveWarehouse($product->company_id, $actor);
            $resolvedWarehouse = Warehouse::query()
                ->whereKey($resolvedWarehouse->id)
                ->where('company_id', $product->company_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $quantityMilli = StockQuantity::toMilli((string) $quantity);
            if ($quantityMilli <= 0) {
                throw ValidationException::withMessages(['quantity' => 'La quantité reçue doit être supérieure à zéro.']);
            }

            if ($idempotencyKey !== null) {
                $existing = StockMovement::query()->where('company_id', $product->company_id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    if (
                        $existing->product_id !== $product->id
                        || $existing->warehouse_id !== $resolvedWarehouse->id
                        || $existing->direction !== 'in'
                        || $existing->type !== $type
                        || $existing->source_type !== $sourceType
                        || $existing->source_id !== $sourceId
                        || StockQuantity::toMilli((string) $existing->quantity) !== $quantityMilli
                    ) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Cette clé a déjà été utilisée pour un autre mouvement.']);
                    }

                    return $existing;
                }
            }

            return $this->stockMovementService->createInboundLot(
                companyId: $product->company_id,
                warehouse: $resolvedWarehouse,
                product: $lockedProduct,
                quantity: StockQuantity::fromMilli($quantityMilli),
                unitCost: $unitCost ?? (string) ($lockedProduct->purchase_price ?? '0.0000'),
                lotNumber: $lotNumber ?? 'LOT-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)),
                receivedAt: $occurredAt,
                expiresAt: $expiresAt,
                supplierId: $supplierId,
                receiptItem: $receiptItem,
                actor: $actor,
                type: $type,
                reference: $reference,
                sourceType: $sourceType,
                sourceId: $sourceId,
                idempotencyKey: $idempotencyKey,
                reason: $reason,
            );
        }, attempts: 3);
    }

    public function recordExit(
        Product $product,
        string|int|float $quantity,
        ?User $actor = null,
        ?Warehouse $warehouse = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
        DateTimeInterface|string|null $occurredAt = null,
        string $type = 'manual_exit',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $reason = 'manual',
    ): StockMovement {
        $actor ??= auth()->user();
        if ($actor === null) {
            throw ValidationException::withMessages(['user' => 'Un utilisateur authentifié est requis pour enregistrer un mouvement de stock.']);
        }

        if ($product->type !== 'product' || ! $product->is_stockable) {
            throw ValidationException::withMessages(['product' => 'Seuls les produits stockables peuvent recevoir des mouvements.']);
        }

        $this->assertSameCompany($product, $actor, 'recordExit');

        $occurredAt ??= now();

        return DB::transaction(function () use ($product, $quantity, $actor, $warehouse, $reference, $idempotencyKey, $occurredAt, $type, $sourceType, $sourceId, $reason): StockMovement {
            $company = Company::query()->whereKey($product->company_id)->lockForUpdate()->firstOrFail();
            $lockedProduct = Product::withTrashed()->whereKey($product->id)->where('company_id', $company->id)->lockForUpdate()->firstOrFail();
            if ($lockedProduct->type !== 'product' || ! $lockedProduct->is_stockable) {
                throw ValidationException::withMessages(['product' => 'Seuls les produits stockables peuvent recevoir des mouvements.']);
            }

            $resolvedWarehouse = $warehouse ?? $this->resolveWarehouse($product->company_id, $actor);
            $resolvedWarehouse = Warehouse::query()
                ->whereKey($resolvedWarehouse->id)
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $requestedMilli = StockQuantity::toMilli((string) $quantity);
            if ($requestedMilli <= 0) {
                throw ValidationException::withMessages(['quantity' => 'La quantité de sortie doit être supérieure à zéro.']);
            }

            if ($idempotencyKey !== null) {
                $existing = StockMovement::query()->where('company_id', $company->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    if (
                        $existing->product_id !== $product->id
                        || $existing->warehouse_id !== $resolvedWarehouse->id
                        || $existing->direction !== 'out'
                        || $existing->type !== $type
                        || $existing->source_type !== $sourceType
                        || $existing->source_id !== $sourceId
                        || StockQuantity::toMilli((string) $existing->quantity) !== $requestedMilli
                    ) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Cette clé a déjà été utilisée pour un autre mouvement.']);
                    }

                    return $existing;
                }
            }

            $currentMilli = StockQuantity::toSignedMilli((string) ($this->getCurrentStock($product, $resolvedWarehouse->id) ?? 0));

            if ($currentMilli < $requestedMilli && ! $company->allow_negative_stock) {
                throw ValidationException::withMessages(['quantity' => 'Le stock disponible est insuffisant pour cette sortie.']);
            }

            return $this->stockMovementService->issue(
                user: $actor,
                product: $lockedProduct,
                quantity: StockQuantity::fromMilli($requestedMilli),
                warehouse: $resolvedWarehouse,
                type: $type,
                reference: $reference,
                sourceType: $sourceType,
                sourceId: $sourceId,
                occurredAt: $occurredAt,
                idempotencyKey: $idempotencyKey,
                reason: $reason,
            );
        }, attempts: 3);
    }

    public function adjustStock(
        Product $product,
        string|int|float $delta,
        ?User $actor = null,
        ?Warehouse $warehouse = null,
        ?string $reference = null,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $unitCost = null,
        ?string $idempotencyKey = null,
        string $type = 'adjustment',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $lotNumber = null,
        ?string $reason = null,
    ): StockMovement {
        $deltaMilli = StockQuantity::toSignedMilli((string) $delta);
        if ($deltaMilli === 0) {
            throw ValidationException::withMessages(['quantity' => 'Le delta de stock doit être différent de zéro.']);
        }

        if ($deltaMilli > 0) {
            return $this->recordEntry(
                product: $product,
                quantity: StockQuantity::fromMilli($deltaMilli),
                actor: $actor,
                warehouse: $warehouse,
                reference: $reference,
                unitCost: $unitCost,
                idempotencyKey: $idempotencyKey,
                occurredAt: $occurredAt,
                type: $type,
                sourceType: $sourceType,
                sourceId: $sourceId,
                lotNumber: $lotNumber,
                reason: $reason,
            );
        }

        return $this->recordExit(
            product: $product,
            quantity: StockQuantity::fromMilli(abs($deltaMilli)),
            actor: $actor,
            warehouse: $warehouse,
            reference: $reference,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt,
            type: $type,
            sourceType: $sourceType,
            sourceId: $sourceId,
            reason: $reason ?? $type,
        );
    }

    private function resolveWarehouse(string $companyId, User $actor): Warehouse
    {
        $warehouse = Warehouse::query()->where('company_id', $companyId)->where('is_default', true)->first();
        if ($warehouse !== null) {
            return $warehouse;
        }

        $warehouse = Warehouse::query()->where('company_id', $companyId)->first();
        if ($warehouse !== null) {
            return $warehouse;
        }

        return $this->stockMovementService->defaultWarehouse($actor, $companyId);
    }

    private function assertSameCompany(Product $product, User $actor, string $operation): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($actor->company_id !== $product->company_id) {
            throw ValidationException::withMessages([
                'company_id' => 'L\'utilisateur ne peut pas '.$operation.' un produit d\'une autre entreprise.',
            ]);
        }
    }
}
