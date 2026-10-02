<?php

namespace App\Http\Controllers\Commercial;

use App\CustomerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\CreateOperatorInvoiceRequest;
use App\Http\Requests\QuickStoreCustomerRequest;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\Commercial\CashSessionService;
use App\Services\Commercial\InvoiceService;
use App\Services\Commercial\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperatorWorkspaceController extends Controller
{
    public function mySession(Request $request, CashSessionService $cashSessionService): View
    {
        $user = $request->user();
        abort_unless($user->hasPermission('cash.view'), 403);

        $session = CashSession::query()->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)
            ->where('company_id', $user->company_id)->with(['cashRegister', 'openedBy'])->first();
        $snapshot = $session ? $cashSessionService->snapshot($session) : null;
        $movements = $session ? $cashSessionService->movements($session) : null;
        $registers = CashRegister::query()->where('company_id', $user->company_id)->where('is_active', true)
            ->whereHas('company', fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')->get();

        return view('cash-registers.my-session', compact('session', 'snapshot', 'movements', 'registers'));
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('customer.view'), 403);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2 || $user->company_id === null) {
            return response()->json(['data' => []]);
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $escapedTerm = addcslashes($term, '\\%_');
        $pattern = '%'.$escapedTerm.'%';
        $customers = Customer::query()->where('company_id', $user->company_id)->where('status', 'active')
            ->where(function (Builder $query) use ($operator, $pattern): void {
                foreach (['legal_name', 'first_name', 'last_name', 'email', 'phone', 'code'] as $column) {
                    $query->orWhere($column, $operator, $pattern);
                }
            })->orderByRaw("CASE WHEN legal_name {$operator} ? THEN 0 WHEN last_name {$operator} ? THEN 1 ELSE 2 END", [$escapedTerm.'%', $escapedTerm.'%'])
            ->limit(10)->get(['id', 'type', 'legal_name', 'first_name', 'last_name', 'email', 'phone', 'code']);

        return response()->json(['data' => $customers->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'name' => $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name),
            'phone' => $customer->phone,
            'email' => $customer->email,
            'code' => $customer->code,
        ])]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('invoice.create'), 403);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2 || $user->company_id === null) {
            return response()->json(['data' => []]);
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.addcslashes($term, '\\%_').'%';
        $products = Product::query()->with('tax')->where('company_id', $user->company_id)->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('name', $operator, $pattern)->orWhere('code', $operator, $pattern)->orWhere('description', $operator, $pattern))
            ->where(fn (Builder $query) => $query->whereNull('tax_id')->orWhereHas('tax', fn ($tax) => $tax->where('company_id', $user->company_id)))
            ->orderBy('name')->limit(10)->get(['id', 'name', 'code', 'description', 'sale_price', 'tax_id']);

        return response()->json(['data' => $products->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->sale_price,
            'tax_rate' => $product->tax?->rate ?? '0.00',
        ])]);
    }

    public function quickStoreCustomer(QuickStoreCustomerRequest $request, CustomerService $customerService): JsonResponse
    {
        $customer = $customerService->create($request->validated());

        return response()->json(['data' => [
            'id' => $customer->id,
            'name' => $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name),
            'phone' => $customer->phone,
            'email' => $customer->email,
            'code' => $customer->code,
        ]], 201);
    }

    public function createInvoice(Request $request): View
    {
        abort_unless($request->user()->hasPermission('invoice.create'), 403);
        $companyId = $request->user()->company_id;
        abort_unless($companyId !== null, 404);
        $paymentMethods = $request->user()->hasPermission('payment.create')
            ? PaymentMethod::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get()
            : collect();
        $bankAccounts = $request->user()->hasPermission('payment.create')
            ? BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get()
            : collect();
        $myOpenSession = CashSession::query()->with('cashRegister')->where('opened_by', $request->user()->id)
            ->where('company_id', $companyId)->where('status', CashSession::STATUS_OPEN)->first();

        $draft = $request->session()->pull('operator_invoice_draft', []);
        $selectedCustomerId = old('customer_id', $draft['customer_id'] ?? null);
        $selectedCustomer = $selectedCustomerId
            ? Customer::query()->where('company_id', $companyId)->whereKey($selectedCustomerId)->first()
            : null;
        $productIds = collect(old('lines', $draft['lines'] ?? []))->pluck('product_id')->filter()->all();
        $draftProducts = Product::query()->with('tax')->where('company_id', $companyId)->whereIn('id', $productIds)->get()
            ->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'price' => $product->sale_price, 'tax_rate' => $product->tax?->rate ?? '0.00'])->values();

        $idempotencyKey = old('idempotency_key', (string) Str::uuid());

        return view('invoices.create', compact('paymentMethods', 'bankAccounts', 'myOpenSession', 'draft', 'selectedCustomer', 'draftProducts', 'idempotencyKey'));
    }

    public function storeInvoice(
        CreateOperatorInvoiceRequest $request,
        InvoiceService $invoiceService,
        PaymentService $paymentService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user->company_id !== null, 404);

        try {
            $invoice = DB::transaction(function () use ($request, $invoiceService, $paymentService, $user): Invoice {
                $invoice = $invoiceService->createForOperator($user, $request->validated());
                if ($request->filled('payment_method_id')) {
                    $paymentService->recordPayment($invoice, [
                        'amount' => $request->validated('payment_amount') ?: $invoice->total,
                        'payment_date' => $request->validated('payment_date') ?: now()->toDateString(),
                        'payment_method_id' => $request->validated('payment_method_id'),
                        'bank_account_id' => $request->validated('bank_account_id'),
                        'reference' => $request->validated('payment_reference'),
                    ]);
                }

                return $invoice;
            }, attempts: 3);
        } catch (ValidationException $exception) {
            if ($exception->errors()['cash_session_id'] ?? false) {
                $request->session()->flash('operator_invoice_draft', $request->validated());
            }
            throw $exception;
        }

        return redirect()->route('invoices.show', $invoice)->with('success', 'Facture enregistrée.');
    }

    public function prepareInvoiceRedirect(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('cash.open'), 403);
        $draft = $request->only(['customer_id', 'issue_date', 'due_date', 'notes', 'lines', 'payment_method_id', 'bank_account_id', 'payment_amount', 'payment_date', 'payment_reference']);
        $request->session()->put('operator_invoice_draft', $draft);

        return redirect()->route('cash.my-session', ['return_to' => 'invoice']);
    }

    public function openMySession(Request $request, CashSessionService $cashSessionService): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('cash.open'), 403);
        $data = $request->validate([
            'cash_register_id' => ['required', 'uuid', 'exists:cash_registers,id'],
            'opening_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'return_to' => ['nullable', 'in:invoice'],
        ]);
        $register = CashRegister::query()->whereKey($data['cash_register_id'])
            ->where('company_id', $request->user()->company_id)->firstOrFail();
        $cashSessionService->open($request->user(), $register, (string) $data['opening_amount']);

        if (($data['return_to'] ?? null) === 'invoice') {
            return redirect()->route('invoices.create')->with('success', 'Caisse ouverte. Votre facture peut être enregistrée.');
        }

        return redirect()->route('cash.my-session')->with('success', 'Votre caisse est ouverte.');
    }
}
