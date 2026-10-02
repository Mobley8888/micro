<?php

namespace App\Services\Commercial;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function __construct(private readonly NumberingService $numberingService) {}

    public function create(array $attributes, array $lines): Quote
    {
        return DB::transaction(function () use ($attributes, $lines): Quote {
            $companyId = $this->companyId();
            if (! Customer::query()->whereKey($attributes['customer_id'])->where('company_id', $companyId)->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Le client sélectionné n’appartient pas à cette entreprise.']);
            }
            $calculated = $this->calculateLines($lines, $companyId);
            $totals = $this->totals($calculated);
            $quote = Quote::create([
                'company_id' => $companyId,
                'customer_id' => $attributes['customer_id'],
                'number' => $this->numberingService->next($companyId, 'DEV', now()->year, 'DEV'),
                'issue_date' => $attributes['issue_date'],
                'due_date' => $attributes['due_date'] ?? null,
                ...$totals,
                'status' => 'draft',
                'notes' => $attributes['notes'] ?? null,
            ]);
            $quote->items()->createMany($calculated);

            return $quote->load('items');
        });
    }

    public function update(Quote $quote, array $attributes, array $lines): Quote
    {
        $this->ensureCompanyOwnsQuote($quote);
        $this->ensureEditable($quote);

        return DB::transaction(function () use ($quote, $attributes, $lines): Quote {
            if (! Customer::query()->whereKey($attributes['customer_id'])->where('company_id', $quote->company_id)->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Le client sélectionné n’appartient pas à cette entreprise.']);
            }

            $calculated = $this->calculateLines($lines, $quote->company_id);
            $quote->update([
                'customer_id' => $attributes['customer_id'],
                'issue_date' => $attributes['issue_date'],
                'due_date' => $attributes['due_date'] ?? null,
                ...$this->totals($calculated),
                'notes' => $attributes['notes'] ?? null,
            ]);
            $quote->items()->delete();
            $quote->items()->createMany($calculated);

            return $quote->refresh()->load('items');
        });
    }

    public function changeStatus(Quote $quote, string $status): Quote
    {
        $this->ensureCompanyOwnsQuote($quote);
        $allowed = [
            'draft' => ['sent', 'cancelled'],
            'sent' => ['accepted', 'rejected', 'expired', 'cancelled'],
            'accepted' => [],
            'rejected' => [],
            'expired' => [],
            'cancelled' => [],
        ];

        if (! in_array($status, $allowed[$quote->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Transition de {$quote->status} vers {$status} refusée."]);
        }

        $quote->update(['status' => $status]);

        return $quote->refresh();
    }

    public function cancel(Quote $quote): Quote
    {
        return DB::transaction(fn (): Quote => $this->changeStatus($quote, 'cancelled'));
    }

    private function calculateLines(array $lines, string $companyId): array
    {
        return collect($lines)->map(function (array $line) use ($companyId): array {
            $product = isset($line['product_id']) && $line['product_id'] !== ''
                ? Product::query()->with('tax')->where('company_id', $companyId)->find($line['product_id'])
                : null;

            if (($line['product_id'] ?? null) !== null && $product === null) {
                throw ValidationException::withMessages(['lines' => 'Un produit sélectionné n’appartient pas à cette entreprise.']);
            }

            $quantityThousandths = (int) round(((float) $line['quantity']) * 1000);
            $unitPriceCents = (int) round(((float) $line['unit_price']) * 100);
            $discountCents = (int) round(((float) ($line['discount_amount'] ?? 0)) * 100);
            $taxBasisPoints = (int) round(((float) ($line['tax_rate'] ?? ($product?->tax?->rate ?? 0))) * 100);
            $grossCents = intdiv($quantityThousandths * $unitPriceCents + 500, 1000);
            $netCents = max(0, $grossCents - $discountCents);
            $taxCents = intdiv($netCents * $taxBasisPoints + 5000, 10000);

            return [
                'product_id' => $product?->id,
                'description' => $line['description'] ?: $product?->name,
                'quantity' => number_format($quantityThousandths / 1000, 3, '.', ''),
                'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                'discount_amount' => number_format($discountCents / 100, 2, '.', ''),
                'tax_rate' => number_format($taxBasisPoints / 100, 2, '.', ''),
                'tax_amount' => number_format($taxCents / 100, 2, '.', ''),
                'total' => number_format(($netCents + $taxCents) / 100, 2, '.', ''),
            ];
        })->values()->all();
    }

    private function totals(array $lines): array
    {
        $subtotal = 0;
        $discount = 0;
        $tax = 0;

        foreach ($lines as $line) {
            $subtotal += (int) round(((float) $line['quantity']) * ((float) $line['unit_price']) * 100);
            $discount += (int) round((float) $line['discount_amount'] * 100);
            $tax += (int) round((float) $line['tax_amount'] * 100);
        }

        $total = max(0, $subtotal - $discount) + $tax;

        return [
            'subtotal' => number_format($subtotal / 100, 2, '.', ''),
            'discount_amount' => number_format($discount / 100, 2, '.', ''),
            'tax_amount' => number_format($tax / 100, 2, '.', ''),
            'total' => number_format($total / 100, 2, '.', ''),
            'amount_paid' => '0.00',
            'balance_due' => number_format($total / 100, 2, '.', ''),
        ];
    }

    private function ensureCompanyOwnsQuote(Quote $quote): void
    {
        if ($quote->company_id !== $this->companyId()) {
            abort(404);
        }
    }

    private function ensureEditable(Quote $quote): void
    {
        if (! in_array($quote->status, ['draft'], true)) {
            throw ValidationException::withMessages(['quote' => 'Seul un devis brouillon peut être modifié.']);
        }
    }

    private function companyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
