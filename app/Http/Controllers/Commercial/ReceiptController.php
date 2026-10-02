<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Commercial\NumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function show(Payment $payment, NumberingService $numberingService): View
    {
        $user = auth()->user();
        $companyId = $user->isSuperAdmin() ? (string) $payment->company_id : (string) $user->company_id;
        abort_unless(($user->isSuperAdmin() || $payment->company_id === $user->company_id) && $payment->status === 'posted', 404);

        DB::transaction(function () use ($payment, $numberingService, $companyId): void {
            $lockedPayment = Payment::query()
                ->where('company_id', $companyId)
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->receipt_number === null) {
                $lockedPayment->receipt_number = $numberingService->next($companyId, 'REC', $lockedPayment->paid_at->year, 'REC');
                $lockedPayment->save();
            }
        });

        $payment->refresh()->load(['company', 'invoice', 'customer', 'paymentMethod', 'receivedBy']);

        return view('receipts.show', compact('payment'));
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
