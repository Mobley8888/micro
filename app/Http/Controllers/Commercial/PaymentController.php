<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StorePaymentRequest;
use App\Models\BankAccount;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Commercial\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $payments = Payment::query()
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $user->company_id))
            ->with(['invoice', 'customer', 'paymentMethod', 'receivedBy'])
            ->latest('paid_at')
            ->paginate(15);

        return view('payments.index', compact('payments'));
    }

    public function invoiceIndex(Invoice $invoice): View
    {
        $user = auth()->user();
        abort_unless($user->isSuperAdmin() || $invoice->company_id === $user->company_id, 404);
        $invoice->load(['customer', 'payments' => fn ($query) => $query
            ->where('company_id', $invoice->company_id)
            ->with('paymentMethod')
            ->orderByDesc('paid_at')]);
        $idempotencyKey = old('idempotency_key', (string) Str::uuid());
        $paymentMethods = PaymentMethod::query()
            ->where('company_id', $invoice->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $bankAccounts = BankAccount::query()->where('company_id', $invoice->company_id)->where('is_active', true)->orderBy('name')->get();
        $cashSessions = $user->hasPermission('cash.move')
            ? CashSession::query()->where('company_id', $invoice->company_id)
                ->when($user->isOperator(), fn ($query) => $query->where('opened_by', $user->id))
                ->where('status', CashSession::STATUS_OPEN)
                ->whereHas('cashRegister', fn ($query) => $query->where('is_active', true))
                ->with('cashRegister')
                ->latest('opened_at')
                ->get()
            : collect();

        return view('payments.create', compact('invoice', 'paymentMethods', 'cashSessions', 'bankAccounts', 'idempotencyKey'));
    }

    public function store(StorePaymentRequest $request, Invoice $invoice, PaymentService $paymentService): RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user->isSuperAdmin() || $invoice->company_id === $user->company_id, 404);
        $paymentService->recordPayment($invoice, $request->validated());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Paiement enregistré et solde de la facture mis à jour.');
    }
}
