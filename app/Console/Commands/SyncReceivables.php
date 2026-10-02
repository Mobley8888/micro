<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Commercial\NumberingService;
use App\Services\Commercial\ReceivableService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncReceivables extends Command
{
    protected $signature = 'micro:sync-receivables';

    protected $description = 'Synchronise les créances et numéros de reçus à partir des factures et paiements existants.';

    public function handle(ReceivableService $receivableService, NumberingService $numberingService): int
    {
        $invoiceCount = 0;
        Invoice::query()->orderBy('id')->chunkById(100, function ($invoices) use ($receivableService, &$invoiceCount): void {
            foreach ($invoices as $invoice) {
                $receivableService->synchronize($invoice);
                $invoiceCount++;
            }
        });

        $receiptCount = 0;
        Payment::query()->where('status', 'posted')->whereNull('receipt_number')->orderBy('id')->chunkById(100, function ($payments) use ($numberingService, &$receiptCount): void {
            foreach ($payments as $payment) {
                DB::transaction(function () use ($payment, $numberingService, &$receiptCount): void {
                    $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                    if ($lockedPayment->receipt_number !== null || $lockedPayment->status !== 'posted') {
                        return;
                    }

                    $year = $lockedPayment->paid_at->year;
                    $lockedPayment->receipt_number = $numberingService->next($lockedPayment->company_id, 'REC', $year, 'REC');
                    $lockedPayment->save();
                    $receiptCount++;
                });
            }
        });

        $this->info("{$invoiceCount} facture(s) synchronisée(s), {$receiptCount} reçu(s) numéroté(s).");

        return self::SUCCESS;
    }
}
