<?php

namespace App\Services\Commercial;

use App\Models\Company;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\Stock\StockValuationService;
use App\Services\Stock\StockService;
use App\Support\CashAmount;
use App\Support\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryCountService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly StockValuationService $valuation,
        private readonly StockService $stock,
    ) {}

    public function create(User $user, string $warehouseId, ?string $notes = null): InventoryCount
    {
        abort_unless($user->hasPermission('stock.inventory'), 403);
        abort_unless($user->company_id !== null, 404);

        return DB::transaction(function () use ($user, $warehouseId, $notes): InventoryCount {
            $companyId = (string) $user->company_id;
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::query()
                ->whereKey($warehouseId)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $products = Product::query()
                ->where('company_id', $companyId)
                ->where('type', 'product')
                ->where('is_stockable', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($products->isEmpty()) {
                throw ValidationException::withMessages(['products' => 'Aucun produit stockable n’est disponible pour cet inventaire.']);
            }

            $count = InventoryCount::query()->create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouse->id,
                'number' => $this->numbering->next($companyId, 'INV', now()->year, 'INV'),
                'status' => 'draft',
                'started_at' => now(),
                'created_by' => $user->id,
                'notes' => $notes,
            ]);

            foreach ($products as $product) {
                $balance = $this->valuation->balance($product, $warehouse->id);
                $unitCost = $balance['unit_cost_scaled'] > 0
                    ? StockQuantity::costFromScale($balance['unit_cost_scaled'])
                    : (string) ($product->purchase_price ?? '0.0000');

                $count->items()->create([
                    'product_id' => $product->id,
                    'expected_quantity' => StockQuantity::fromMilli($balance['quantity_milli']),
                    'unit_cost' => $unitCost,
                ]);
            }

            return $count->load(['warehouse', 'createdBy', 'items.product']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, string|null>  $counts
     * @param  array<string, string|null>  $itemNotes
     */
    public function recordCounts(
        User $user,
        InventoryCount $inventoryCount,
        array $counts,
        array $itemNotes = [],
        ?string $notes = null,
    ): InventoryCount {
        abort_unless($user->hasPermission('stock.inventory'), 403);
        abort_unless($user->company_id !== null, 404);

        return DB::transaction(function () use ($user, $inventoryCount, $counts, $itemNotes, $notes): InventoryCount {
            $count = InventoryCount::query()
                ->where('company_id', $user->company_id)
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($count->status !== 'draft') {
                throw ValidationException::withMessages(['inventory' => 'Un inventaire validé ne peut plus être modifié.']);
            }

            $items = $count->items()->lockForUpdate()->get()->keyBy('id');
            $submittedIds = array_unique([...array_keys($counts), ...array_keys($itemNotes)]);
            foreach ($submittedIds as $itemId) {
                if (! $items->has($itemId)) {
                    abort(404);
                }
            }

            $recordedAnyCount = false;
            foreach ($counts as $itemId => $quantity) {
                if ($quantity === null || trim($quantity) === '') {
                    continue;
                }

                $item = $items->get($itemId);
                $countedMilli = StockQuantity::toMilli($quantity);
                $expectedMilli = StockQuantity::toSignedMilli((string) $item->expected_quantity);
                $differenceMilli = $countedMilli - $expectedMilli;
                $item->forceFill([
                    'counted_quantity' => StockQuantity::fromMilli($countedMilli),
                    'difference_quantity' => StockQuantity::fromMilli($differenceMilli),
                    'difference_value' => CashAmount::fromCents($this->differenceValueCents($differenceMilli, (string) $item->unit_cost)),
                ]);
                if (array_key_exists($itemId, $itemNotes)) {
                    $item->notes = $itemNotes[$itemId];
                }
                $item->save();
                $recordedAnyCount = true;
            }

            foreach ($itemNotes as $itemId => $itemNote) {
                if (! array_key_exists($itemId, $counts) || $counts[$itemId] === null || trim($counts[$itemId]) === '') {
                    $items->get($itemId)->forceFill(['notes' => $itemNote])->save();
                }
            }

            if (! $recordedAnyCount) {
                throw ValidationException::withMessages(['counts' => 'Saisissez au moins une quantité comptée.']);
            }

            $count->forceFill(['notes' => $notes])->save();

            return $count->load(['warehouse', 'createdBy', 'validatedBy', 'items.product']);
        }, attempts: 3);
    }

    public function validateCount(User $user, InventoryCount $inventoryCount): InventoryCount
    {
        abort_unless($user->hasPermission('stock.validate'), 403);
        abort_unless($user->company_id !== null, 404);

        return DB::transaction(function () use ($user, $inventoryCount): InventoryCount {
            $count = InventoryCount::query()
                ->where('company_id', $user->company_id)
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($count->status !== 'draft') {
                throw ValidationException::withMessages(['inventory' => 'Cet inventaire a déjà été validé.']);
            }

            $warehouse = Warehouse::query()
                ->whereKey($count->warehouse_id)
                ->where('company_id', $count->company_id)
                ->firstOrFail();
            $items = $count->items()->with('product')->orderBy('product_id')->lockForUpdate()->get();

            if ($items->isEmpty() || $items->contains(fn (InventoryCountItem $item): bool => $item->counted_quantity === null)) {
                throw ValidationException::withMessages(['counts' => 'Toutes les quantités de l’inventaire doivent être comptées avant validation.']);
            }

            foreach ($items as $item) {
                $differenceMilli = StockQuantity::toSignedMilli((string) $item->difference_quantity);
                if ($differenceMilli === 0) {
                    continue;
                }

                $product = Product::withTrashed()
                    ->whereKey($item->product_id)
                    ->where('company_id', $count->company_id)
                    ->where('type', 'product')
                    ->where('is_stockable', true)
                    ->firstOrFail();

                $this->stock->adjustStock(
                    product: $product,
                    delta: StockQuantity::fromMilli($differenceMilli),
                    actor: $user,
                    warehouse: $warehouse,
                    reference: $count->number,
                    unitCost: (string) $item->unit_cost,
                    idempotencyKey: $item->id,
                    type: 'inventory_adjustment',
                    sourceType: InventoryCountItem::class,
                    sourceId: $item->id,
                    lotNumber: $count->number.'-'.$item->id,
                );
            }

            $count->forceFill([
                'status' => 'validated',
                'validated_at' => now(),
                'validated_by' => $user->id,
            ])->save();

            return $count->load(['warehouse', 'createdBy', 'validatedBy', 'items.product']);
        }, attempts: 3);
    }

    public function findForUser(User $user, InventoryCount $inventoryCount): InventoryCount
    {
        abort_unless($user->hasPermission('stock.view'), 403);

        return InventoryCount::query()
            ->where('company_id', $user->company_id)
            ->whereKey($inventoryCount->id)
            ->with(['warehouse', 'createdBy', 'validatedBy', 'items.product'])
            ->firstOrFail();
    }

    private function differenceValueCents(int $differenceMilli, string $unitCost): int
    {
        if ($differenceMilli === 0) {
            return 0;
        }

        $value = StockQuantity::lineValueCents(abs($differenceMilli), StockQuantity::costToScale($unitCost));

        return $differenceMilli < 0 ? -$value : $value;
    }
}
