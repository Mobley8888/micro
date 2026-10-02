<?php

namespace App\Services\Commercial;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Receivable;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\StockService;
use App\Support\CashAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly NumberingService $numberingService,
        private readonly ReceivableService $receivableService,
        private readonly StockService $stockService,
    ) {}

    public function convertFromQuote(Quote $quote): Invoice
    {
        return DB::transaction(function () use ($quote): Invoice {
            $lockedQuote = Quote::query()->whereKey($quote->getKey())->lockForUpdate()->firstOrFail();
            $lockedQuote->load('items');

            if (Auth::user() === null || (! Auth::user()->isSuperAdmin() && Auth::user()->company_id !== $lockedQuote->company_id)) {
                abort(404);
            }

            if ($lockedQuote->status !== 'accepted') {
                throw ValidationException::withMessages(['quote' => 'Seul un devis accepté peut être converti en facture.']);
            }

            if (Invoice::query()->where('quote_id', $lockedQuote->getKey())->exists()) {
                throw ValidationException::withMessages(['quote' => 'Ce devis possède déjà une facture.']);
            }

            $invoice = Invoice::create([
                'company_id' => $lockedQuote->company_id,
                'customer_id' => $lockedQuote->customer_id,
                'quote_id' => $lockedQuote->getKey(),
                'number' => $this->numberingService->next($lockedQuote->company_id, 'FAC', now()->year, 'FAC'),
                'issue_date' => $lockedQuote->issue_date,
                'due_date' => $lockedQuote->due_date,
                'subtotal' => $lockedQuote->subtotal,
                'discount_amount' => $lockedQuote->discount_amount,
                'tax_amount' => $lockedQuote->tax_amount,
                'total' => $lockedQuote->total,
                'amount_paid' => '0.00',
                'balance_due' => $lockedQuote->total,
                'status' => 'draft',
                'notes' => $lockedQuote->notes,
            ]);

            $invoice->items()->createMany($lockedQuote->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $item->tax_amount,
                'total' => $item->total,
            ])->all());
            $this->receivableService->synchronize($invoice);

            return $invoice->load(['quote', 'items', 'receivable']);
        });
    }

    /** @param array{customer_id:string,issue_date:string,due_date?:?string,notes?:?string,lines:list<array{product_id:string,quantity:numeric-string|int|float}>} $data */
    public function createForOperator(User $user, array $data): Invoice
    {
        abort_unless($user->hasPermission('invoice.create'), 403);
        abort_unless($user->company_id !== null, 404);

        return DB::transaction(function () use ($user, $data): Invoice {
            $companyId = (string) $user->company_id;
            $customer = Customer::query()->whereKey($data['customer_id'])
                ->where('company_id', $companyId)->where('status', 'active')->lockForUpdate()->first();

            if ($customer === null) {
                throw ValidationException::withMessages(['customer_id' => 'Le client sélectionné est indisponible.']);
            }

            $subtotalCents = 0;
            $taxCents = 0;
            $discountCents = 0;
            $items = [];

            foreach ($data['lines'] as $line) {
                $product = Product::query()->with('tax')->where('company_id', $companyId)
                    ->where('is_active', true)->whereKey($line['product_id'])
                    ->where(fn ($query) => $query->whereNull('tax_id')->orWhereHas('tax', fn ($tax) => $tax->where('company_id', $companyId)))
                    ->first();

                if ($product === null) {
                    throw ValidationException::withMessages(['lines' => 'Un article sélectionné n’appartient pas à cette entreprise ou est inactif.']);
                }

                $quantityMilli = self::decimalToScaledInteger((string) $line['quantity'], 3);
                $unitPriceCents = CashAmount::toCents($product->sale_price);
                $taxRateBasisPoints = self::decimalToScaledInteger((string) ($product->tax?->rate ?? '0'), 2);
                if ($unitPriceCents > 0 && $quantityMilli > intdiv(PHP_INT_MAX - 500, $unitPriceCents)) {
                    throw ValidationException::withMessages(['lines' => 'Le montant de cette ligne dépasse la limite de calcul autorisée.']);
                }

                $lineSubtotalCents = intdiv($quantityMilli * $unitPriceCents + 500, 1000);
                $lineDiscountCents = CashAmount::toCents((string) ($line['discount_amount'] ?? '0.00'));
                if ($lineDiscountCents > $lineSubtotalCents) {
                    throw ValidationException::withMessages(['lines' => 'La remise ne peut pas dépasser le montant de la ligne.']);
                }

                $lineTaxableCents = $lineSubtotalCents - $lineDiscountCents;
                if ($taxRateBasisPoints > 0 && $lineTaxableCents > intdiv(PHP_INT_MAX - 5000, $taxRateBasisPoints)) {
                    throw ValidationException::withMessages(['lines' => 'La taxe de cette ligne dépasse la limite de calcul autorisée.']);
                }

                $lineTaxCents = intdiv($lineTaxableCents * $taxRateBasisPoints + 5000, 10000);
                if ($subtotalCents > PHP_INT_MAX - $lineSubtotalCents || $taxCents > PHP_INT_MAX - $lineTaxCents || $discountCents > PHP_INT_MAX - $lineDiscountCents) {
                    throw ValidationException::withMessages(['lines' => 'Le total dépasse la limite de calcul autorisée.']);
                }

                $subtotalCents += $lineSubtotalCents;
                $taxCents += $lineTaxCents;
                $discountCents += $lineDiscountCents;
                $items[] = [
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => self::formatScaledInteger($quantityMilli, 3),
                    'unit_price' => CashAmount::fromCents($unitPriceCents),
                    'discount_amount' => CashAmount::fromCents($lineDiscountCents),
                    'tax_rate' => CashAmount::fromCents($taxRateBasisPoints),
                    'tax_amount' => CashAmount::fromCents($lineTaxCents),
                    'total' => CashAmount::fromCents($lineTaxableCents + $lineTaxCents),
                ];
            }

            if ($subtotalCents < $discountCents || $subtotalCents - $discountCents > PHP_INT_MAX - $taxCents) {
                throw ValidationException::withMessages(['lines' => 'Le total dépasse la limite de calcul autorisée.']);
            }

            $totalCents = $subtotalCents - $discountCents + $taxCents;
            $invoice = Invoice::query()->create([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'number' => $this->numberingService->next($companyId, 'FAC', CarbonImmutable::parse($data['issue_date'])->year, 'FAC'),
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => CashAmount::fromCents($subtotalCents),
                'discount_amount' => CashAmount::fromCents($discountCents),
                'tax_amount' => CashAmount::fromCents($taxCents),
                'total' => CashAmount::fromCents($totalCents),
                'amount_paid' => '0.00',
                'balance_due' => CashAmount::fromCents($totalCents),
                'status' => 'sent',
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->items()->createMany($items);
            $this->recordStockExits($user, $invoice->load('items.product'));
            $this->receivableService->synchronize($invoice);

            return $invoice->load(['customer', 'items', 'receivable']);
        }, attempts: 3);
    }

    public function issue(User $user, Invoice $invoice): Invoice
    {
        abort_unless($user->hasPermission('invoice.create'), 403);

        return DB::transaction(function () use ($user, $invoice): Invoice {
            $companyId = $user->isSuperAdmin() ? $invoice->company_id : $user->company_id;
            abort_unless($companyId !== null, 404);
            $lockedInvoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->load('items.product');

            if ($lockedInvoice->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => 'Seule une facture brouillon peut être validée.']);
            }

            $this->recordStockExits($user, $lockedInvoice);
            $lockedInvoice->forceFill(['status' => 'sent'])->save();

            return $lockedInvoice->refresh()->load(['customer', 'items.product', 'receivable']);
        }, attempts: 3);
    }

    public function cancel(User $user, Invoice $invoice): Invoice
    {
        abort_unless($user->hasPermission('invoice.create'), 403);

        return DB::transaction(function () use ($user, $invoice): Invoice {
            $companyId = $user->isSuperAdmin() ? $invoice->company_id : $user->company_id;
            abort_unless($companyId !== null, 404);
            $lockedInvoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->load('items.product');

            if (! in_array($lockedInvoice->status, ['draft', 'sent'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Cette facture ne peut pas être annulée.']);
            }

            if ($lockedInvoice->payments()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Une facture avec des paiements doit être régularisée financièrement avant son annulation.']);
            }

            if ($lockedInvoice->status === 'sent') {
                foreach ($lockedInvoice->items as $item) {
                    if ($item->product_id === null) {
                        continue;
                    }

                    $saleMovement = StockMovement::query()
                        ->where('company_id', $lockedInvoice->company_id)
                        ->where('source_type', InvoiceItem::class)
                        ->where('source_id', $item->id)
                        ->where('type', 'sale')
                        ->first();

                    if ($saleMovement === null) {
                        continue;
                    }

                    $product = Product::withTrashed()
                        ->whereKey($item->product_id)
                        ->where('company_id', $lockedInvoice->company_id)
                        ->first();
                    if ($product === null || $product->type !== 'product' || ! $product->is_stockable) {
                        throw ValidationException::withMessages(['invoice' => 'Un produit vendu n’est plus disponible comme produit stockable; la facture ne peut pas être annulée sans régularisation manuelle.']);
                    }

                    $warehouse = Warehouse::query()
                        ->whereKey($saleMovement->warehouse_id)
                        ->where('company_id', $lockedInvoice->company_id)
                        ->firstOrFail();

                    $this->stockService->adjustStock(
                        product: $product,
                        delta: (string) $item->quantity,
                        actor: $user,
                        warehouse: $warehouse,
                        reference: 'ANN-'.$lockedInvoice->number,
                        unitCost: (string) $saleMovement->unit_cost,
                        idempotencyKey: (string) Str::uuid(),
                        type: 'sale_reversal',
                        sourceType: InvoiceItem::class,
                        sourceId: $item->id,
                        lotNumber: 'ANN-'.$lockedInvoice->number.'-'.$item->id,
                    );
                }
            }

            $lockedInvoice->forceFill([
                'status' => 'cancelled',
                'financial_status' => 'cancelled',
                'amount_paid' => '0.00',
                'balance_due' => '0.00',
            ])->save();
            Receivable::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->lockForUpdate()
                ->first()
                ?->forceFill(['amount_paid' => '0.00', 'balance_due' => '0.00', 'status' => 'cancelled'])
                ?->save();

            return $lockedInvoice->refresh()->load(['customer', 'items.product', 'receivable']);
        }, attempts: 3);
    }

    private function recordStockExits(User $user, Invoice $invoice): void
    {
        foreach ($invoice->items->sortBy('id') as $item) {
            $product = $item->product_id === null
                ? null
                : Product::withTrashed()->whereKey($item->product_id)->where('company_id', $invoice->company_id)->first();
            if ($product === null || $product->type !== 'product' || ! $product->is_stockable) {
                continue;
            }

            if ($product->company_id !== $invoice->company_id) {
                abort(404);
            }

            $movement = $this->stockService->recordExit(
                product: $product,
                quantity: (string) $item->quantity,
                actor: $user,
                reference: $invoice->number,
                idempotencyKey: $item->id,
                occurredAt: $invoice->issue_date->toDateString(),
                type: 'sale',
                sourceType: InvoiceItem::class,
                sourceId: $item->id,
                reason: 'invoice_sale',
            );

            $item->forceFill([
                'cost_of_goods_sold' => $movement->total_cost,
                'stock_cost_method' => $movement->metadata['valuation_method'] ?? 'FIFO',
                'stock_cost_details' => $movement->allocations()
                    ->get(['stock_lot_id', 'quantity', 'unit_cost', 'total_cost'])
                    ->toArray(),
            ])->save();
        }
    }

    private static function formatScaledInteger(int $value, int $scale): string
    {
        $factor = 10 ** $scale;

        return intdiv($value, $factor).'.'.str_pad((string) ($value % $factor), $scale, '0', STR_PAD_LEFT);
    }

    private static function decimalToScaledInteger(string $value, int $scale): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw ValidationException::withMessages(['lines' => 'Une quantité ou un taux de taxe est invalide.']);
        }

        $fraction = substr(str_pad($matches[2] ?? '', $scale, '0'), 0, $scale);

        return ((int) $matches[1] * (10 ** $scale)) + (int) $fraction;
    }
}
