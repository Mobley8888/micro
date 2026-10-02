<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Commercial\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $invoices = Invoice::query()->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $user->company_id))->with(['customer', 'quote'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('number', 'ilike', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('legal_name', 'ilike', "%{$search}%")->orWhere('first_name', 'ilike', "%{$search}%")->orWhere('last_name', 'ilike', "%{$search}%"));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('issue_date')
            ->paginate(15)
            ->withQueryString();

        return view('invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice): View
    {
        $user = auth()->user();
        abort_unless($user->isSuperAdmin() || $invoice->company_id === $user->company_id, 404);
        $companyId = $invoice->company_id;
        $invoice->load(['company', 'customer', 'quote', 'items.product', 'payments' => fn ($query) => $query
            ->where('company_id', $companyId)
            ->with(['paymentMethod', 'receivedBy'])
            ->orderByDesc('paid_at')]);

        return view('invoices.show', compact('invoice'));
    }

    public function convertFromQuote(Quote $quote, InvoiceService $invoiceService): RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user->isSuperAdmin() || $quote->company_id === $user->company_id, 404);
        $invoice = $invoiceService->convertFromQuote($quote);

        return redirect()->route('invoices.show', $invoice)->with('success', 'Facture créée depuis le devis accepté.');
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoiceService): RedirectResponse
    {
        $issuedInvoice = $invoiceService->issue($request->user(), $invoice);

        return redirect()->route('invoices.show', $issuedInvoice)->with('success', 'Facture validée et sorties de stock enregistrées.');
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $invoiceService): RedirectResponse
    {
        $cancelledInvoice = $invoiceService->cancel($request->user(), $invoice);

        return redirect()->route('invoices.show', $cancelledInvoice)->with('success', 'Facture annulée et stock régularisé si nécessaire.');
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
