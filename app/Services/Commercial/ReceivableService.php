<?php

namespace App\Services\Commercial;

use App\Models\Invoice;
use App\Models\Receivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceivableService
{
    public function synchronize(Invoice $invoice): Receivable
    {
        return DB::transaction(function () use ($invoice): Receivable {
            $lockedInvoice = Invoice::query()
                ->where('company_id', $invoice->company_id)
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $amountDueCents = $this->toCents($lockedInvoice->total);
            $amountPaidCents = $this->toCents($lockedInvoice->payments()
                ->where('company_id', $lockedInvoice->company_id)
                ->where('status', 'posted')
                ->sum('amount'));
            $amountPaidCents = min($amountDueCents, $amountPaidCents);
            $balanceDueCents = $lockedInvoice->status === 'cancelled'
                ? 0
                : max(0, $amountDueCents - $amountPaidCents);
            $financialStatus = $lockedInvoice->status === 'cancelled'
                ? 'cancelled'
                : match (true) {
                    $balanceDueCents === 0 => 'paid',
                    $amountPaidCents > 0 => 'partially_paid',
                    default => 'unpaid',
                };

            $receivable = Receivable::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->first();
            if ($receivable === null) {
                $receivable = new Receivable(['id' => (string) Str::uuid(), 'invoice_id' => $lockedInvoice->id]);
            }

            $receivable->fill([
                'customer_id' => $lockedInvoice->customer_id,
                'amount_due' => $this->fromCents($amountDueCents),
                'amount_paid' => $this->fromCents($amountPaidCents),
                'balance_due' => $this->fromCents($balanceDueCents),
                'due_date' => $lockedInvoice->due_date,
                'status' => $financialStatus,
            ])->save();

            $runningPaidCents = 0;
            $lockedInvoice->payments()
                ->where('company_id', $lockedInvoice->company_id)
                ->where('status', 'posted')
                ->orderBy('paid_at')
                ->orderBy('number')
                ->get()
                ->each(function ($payment) use (&$runningPaidCents, $amountDueCents): void {
                    $runningPaidCents += $this->toCents($payment->amount);
                    $payment->forceFill(['balance_after' => $this->fromCents(max(0, $amountDueCents - $runningPaidCents))])->save();
                });

            $lockedInvoice->forceFill([
                'amount_paid' => $this->fromCents($amountPaidCents),
                'balance_due' => $this->fromCents($balanceDueCents),
                'financial_status' => $financialStatus,
            ])->save();

            return $receivable->refresh();
        });
    }

    private function toCents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function fromCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
