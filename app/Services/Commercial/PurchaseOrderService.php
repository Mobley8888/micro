<?php

namespace App\Services\Commercial;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\StockService;
use App\Support\CashAmount;
use App\Support\StockQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(private readonly NumberingService $numbering, private readonly StockService $stock) {}

    public function create(User $user, array $data): PurchaseOrder
    {
        abort_unless($user->hasPermission('purchases.create'), 403);

        return DB::transaction(function () use ($user, $data): PurchaseOrder {
            $companyId = (string) $user->company_id;
            $supplier = Supplier::query()->where('company_id', $companyId)->where('is_active', true)->whereKey($data['supplier_id'])->firstOrFail();
            $order = PurchaseOrder::query()->create([
                'company_id' => $companyId, 'supplier_id' => $supplier->id,
                'number' => $this->numbering->next($companyId, 'PUR', now()->year, 'PUR'),
                'ordered_at' => $data['ordered_at'], 'expected_at' => $data['expected_at'] ?? null,
                'status' => 'draft', 'currency' => $supplier->currency, 'notes' => $data['notes'] ?? null,
                'created_by' => $user->id, 'idempotency_key' => (string) Str::uuid(),
                'subtotal' => '0.00', 'discount_amount' => '0.00', 'tax_amount' => '0.00', 'total' => '0.00',
            ]);
            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::query()->where('company_id', $companyId)->where('type', 'product')->where('is_stockable', true)->where('is_active', true)->whereKey($line['product_id'])->first();
                if ($product === null) {
                    throw ValidationException::withMessages(['items' => 'Seuls les produits stockables actifs de votre entreprise peuvent être achetés.']);
                }
                $quantity = StockQuantity::toMilli((string) $line['quantity']);
                $unitCost = StockQuantity::costToScale((string) $line['unit_cost']);
                $amount = intdiv($quantity * $unitCost + 50000, 100000);
                $subtotal += $amount;
                $order->items()->create([
                    'product_id' => $product->id, 'product_code' => $product->code, 'description' => $product->name,
                    'quantity' => StockQuantity::fromMilli($quantity), 'unit' => $line['unit'] ?? 'unité',
                    'unit_cost' => StockQuantity::costFromScale($unitCost), 'discount_amount' => '0.00',
                    'tax_rate' => '0.00', 'tax_amount' => '0.00', 'line_total' => CashAmount::fromCents($amount),
                ]);
            }
            $order->forceFill(['subtotal' => CashAmount::fromCents($subtotal), 'total' => CashAmount::fromCents($subtotal)])->save();

            return $order->load(['supplier', 'items.product']);
        }, attempts: 3);
    }

    public function confirm(User $user, PurchaseOrder $order): PurchaseOrder
    {
        abort_unless($user->hasPermission('purchases.confirm'), 403);

        return DB::transaction(function () use ($user, $order): PurchaseOrder {
            $locked = PurchaseOrder::query()->where('company_id', $user->company_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' || $locked->items()->doesntExist()) {
                throw ValidationException::withMessages(['purchase' => 'Seule une commande brouillon non vide peut être confirmée.']);
            }
            $locked->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();

            return $locked;
        }, attempts: 3);
    }

    /** @param array<string, string> $quantities keyed by purchase order item id */
    public function receive(User $user, PurchaseOrder $order, array $quantities): PurchaseReceipt
    {
        abort_unless($user->hasPermission('purchases.receive'), 403);

        return DB::transaction(function () use ($user, $order, $quantities): PurchaseReceipt {
            $locked = PurchaseOrder::query()->with(['items', 'supplier'])->where('company_id', $user->company_id)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['confirmed', 'partially_received'], true)) {
                throw ValidationException::withMessages(['purchase' => 'Cette commande ne peut pas être réceptionnée.']);
            }
            $receipt = PurchaseReceipt::query()->create([
                'company_id' => $locked->company_id, 'supplier_id' => $locked->supplier_id, 'purchase_order_id' => $locked->id,
                'number' => $this->numbering->next($locked->company_id, 'REC', now()->year, 'REC'), 'received_at' => now(),
                'status' => 'posted', 'received_by' => $user->id, 'validated_at' => now(), 'idempotency_key' => (string) Str::uuid(),
            ]);
            $receivedAny = false;
            foreach ($locked->items as $item) {
                $quantityText = $quantities[$item->id] ?? '0';
                $quantity = StockQuantity::toMilli((string) $quantityText);
                if ($quantity === 0) {
                    continue;
                }
                $ordered = StockQuantity::toMilli((string) $item->quantity);
                $received = StockQuantity::toMilli((string) $item->received_quantity);
                if ($quantity > $ordered - $received) {
                    throw ValidationException::withMessages(['quantities.'.$item->id => 'La quantité dépasse le reliquat de la commande.']);
                }
                $receiptItem = $receipt->items()->create([
                    'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id,
                    'quantity' => StockQuantity::fromMilli($quantity), 'unit_cost' => $item->unit_cost,
                    'lot_number' => $receipt->number.'-'.$item->id,
                ]);
                $product = Product::withTrashed()->where('company_id', $locked->company_id)->whereKey($item->product_id)->firstOrFail();
                $this->stock->recordEntry(
                    product: $product,
                    quantity: (string) $receiptItem->quantity,
                    actor: $user,
                    reference: $receipt->number,
                    unitCost: (string) $receiptItem->unit_cost,
                    idempotencyKey: $receiptItem->id,
                    occurredAt: $receipt->received_at,
                    type: 'purchase_receipt',
                    sourceType: PurchaseReceiptItem::class,
                    sourceId: $receiptItem->id,
                    supplierId: $receipt->supplier_id,
                    receiptItem: $receiptItem,
                    lotNumber: $receiptItem->lot_number ?: $receipt->number.'-'.$receiptItem->id,
                    expiresAt: $receiptItem->expires_at?->toDateString(),
                );
                $item->forceFill(['received_quantity' => StockQuantity::fromMilli($received + $quantity)])->save();
                $receivedAny = true;
            }
            if (! $receivedAny) {
                throw ValidationException::withMessages(['quantities' => 'Saisissez au moins une quantité à réceptionner.']);
            }
            $fullyReceived = $locked->items->every(fn ($item): bool => StockQuantity::toMilli((string) $item->fresh()->received_quantity) >= StockQuantity::toMilli((string) $item->quantity));
            $locked->forceFill(['status' => $fullyReceived ? 'received' : 'partially_received'])->save();

            return $receipt->load(['items.product', 'supplier', 'purchaseOrder']);
        }, attempts: 3);
    }
}
