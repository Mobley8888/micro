<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialJournalEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Commercial\CashSessionService;
use App\Services\Commercial\FinancialOperationsService;
use App\Support\CashAmount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialOperationsController extends Controller
{
    private function scoped(Builder $query, User $user): Builder
    {
        return $query->when(! $user->isSuperAdmin(), fn ($builder) => $builder->where('company_id', $user->company_id));
    }

    public function expenses(Request $request): View
    {
        $user = $request->user();
        $expenses = $this->scoped(Expense::query(), $user)->with(['category', 'paymentMethod', 'createdBy'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(description) LIKE ?', ['%'.mb_strtolower($request->string('search')).'%'])->orWhereRaw('LOWER(COALESCE(supplier, ?)) LIKE ?', ['', '%'.mb_strtolower($request->string('search')).'%'])->orWhereRaw('LOWER(COALESCE(reference, ?)) LIKE ?', ['', '%'.mb_strtolower($request->string('search')).'%'])))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->input('to')))
            ->orderByDesc('expense_date')->paginate(15)->withQueryString();

        return view('expenses.index', compact('expenses'));
    }

    public function createExpense(Request $request): View
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() ? $request->query('company_id', $user->company_id) : $user->company_id;

        return view('expenses.form', [
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
            'categories' => ExpenseCategory::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'methods' => PaymentMethod::query()->where('company_id', $companyId)->where('is_active', true)->when(! $user->hasPermission('expense.create'), fn ($q) => $q->where('code', 'cash'))->orderBy('name')->get(),
            'accounts' => $user->hasPermission('expense.create') ? BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get() : collect(),
            'sessions' => CashSession::query()->where('company_id', $companyId)->where('status', 'open')->whereHas('cashRegister', fn ($q) => $q->where('is_active', true))->with(['cashRegister', 'openedBy'])->get(),
            'companies' => $user->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect(),
            'companyId' => $companyId,
        ]);
    }

    public function createTransfer(Request $request): View
    {
        $user = $request->user();
        $companyId = $user->isSuperAdmin() ? $request->query('company_id', $user->company_id) : $user->company_id;

        return view('financial-transfers.create', [
            'accounts' => BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(),
            'sessions' => CashSession::query()->where('company_id', $companyId)->where('status', 'open')->whereHas('cashRegister', fn ($q) => $q->where('is_active', true))->with(['cashRegister', 'openedBy'])->get(),
            'companies' => $user->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect(),
            'companyId' => $companyId,
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
        ]);
    }

    public function storeExpense(Request $request, FinancialOperationsService $service): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'expense_category_id' => ['required', 'uuid'],
            'supplier' => ['nullable', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'], 'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'expense_date' => ['required', 'date'], 'payment_method_id' => ['required', 'uuid'],
            'cash_session_id' => ['nullable', 'uuid'], 'bank_account_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'], 'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], 'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $attachment = $data['attachment'] ?? null;
        unset($data['attachment']);
        $expense = $service->createExpense($request->user(), $data);
        if ($attachment !== null && $expense->attachment_path === null) {
            $companyId = $request->user()->isSuperAdmin() ? ($data['company_id'] ?? $request->user()->company_id) : $request->user()->company_id;
            $path = $attachment->store('expenses/'.$companyId, 'local');
            abort_unless(is_string($path), 500, 'Le justificatif n’a pas pu être enregistré.');
            $expense->update(['attachment_path' => $path]);
        }

        return redirect()->route('expenses.index')->with('success', 'Dépense enregistrée avec son mouvement financier.');
    }

    public function attachment(Request $request, Expense $expense): StreamedResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $expense->company_id === $request->user()->company_id, 404);
        abort_unless($expense->attachment_path !== null && Storage::disk('local')->exists($expense->attachment_path), 404);

        return Storage::disk('local')->download($expense->attachment_path, basename($expense->attachment_path));
    }

    public function cancelExpense(Request $request, Expense $expense, FinancialOperationsService $service): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('expense.cancel'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->cancelExpense($request->user(), $expense, $data['reason']);

        return back()->with('success', 'Dépense annulée par contrepassation.');
    }

    public function storeCategory(Request $request, FinancialOperationsService $service): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:100', 'alpha_dash'], 'description' => ['nullable', 'string', 'max:1000']]);
        $service->createCategory($request->user(), $data);

        return back()->with('success', 'Catégorie créée.');
    }

    public function banks(Request $request): View
    {
        $user = $request->user();
        $accounts = $this->scoped(BankAccount::query(), $user)->withCount(['transactions as unreconciled_count' => fn ($q) => $q->whereNull('reconciled_at')])->orderBy('name')->get();
        $companies = $user->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect();

        return view('banks.index', compact('accounts', 'companies'));
    }

    public function storeBank(Request $request, FinancialOperationsService $service): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'name' => ['required', 'string', 'max:255'], 'bank_name' => ['nullable', 'string', 'max:255'], 'account_number' => ['nullable', 'string', 'max:255'], 'account_type' => ['nullable', 'string', 'max:100'], 'currency' => ['nullable', 'string', 'size:3'], 'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $service->createBankAccount($request->user(), $data);

        return redirect()->route('banks.index')->with('success', 'Compte bancaire créé.');
    }

    public function bankTransactions(Request $request, BankAccount $bankAccount): View
    {
        abort_unless($request->user()->isSuperAdmin() || $bankAccount->company_id === $request->user()->company_id, 404);
        $transactions = $bankAccount->transactions()->with('createdBy')->orderByDesc('transaction_date')->paginate(20);
        $idempotencyKey = old('idempotency_key', (string) Str::uuid());
        $statementBalance = $request->query('statement_balance');
        $difference = $statementBalance !== null && is_numeric($statementBalance)
            ? CashAmount::fromCents(CashAmount::toCents((string) $statementBalance) - CashAmount::toCents($bankAccount->balance()))
            : null;

        return view('banks.transactions', compact('bankAccount', 'transactions', 'statementBalance', 'difference', 'idempotencyKey'));
    }

    public function storeBankTransaction(Request $request, BankAccount $bankAccount, FinancialOperationsService $service): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('bank.transaction'), 403);
        $data = $request->validate(['type' => ['required', 'in:deposit,withdrawal,fee,adjustment'], 'direction' => ['required', 'in:in,out'], 'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'], 'transaction_date' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['required', 'string', 'max:2000'], 'counterparty' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['nullable', 'uuid']]);
        $service->recordBankTransaction($request->user(), $bankAccount, $data);

        return back()->with('success', 'Transaction bancaire enregistrée.');
    }

    public function reconcile(Request $request, BankTransaction $bankTransaction, FinancialOperationsService $service): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('bank.reconcile'), 403);
        $data = $request->validate(['reconciled' => ['required', 'boolean']]);
        $service->reconcile($request->user(), $bankTransaction, (bool) $data['reconciled']);

        return back()->with('success', 'Rapprochement mis à jour.');
    }

    public function storeTransfer(Request $request, FinancialOperationsService $service): RedirectResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'from_type' => ['required', 'in:cash,bank'], 'from_id' => ['nullable', 'uuid'], 'to_type' => ['required', 'in:cash,bank'], 'to_id' => ['nullable', 'uuid'], 'cash_session_id' => ['nullable', 'uuid'], 'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'], 'transferred_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['nullable', 'string', 'max:100']]);
        $service->transfer($request->user(), $data);

        return redirect()->route('banks.index')->with('success', 'Transfert enregistré dans les deux comptes.');
    }

    public function journal(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'account_type' => ['nullable', 'in:cash,bank,payment_method'], 'account_id' => ['nullable', 'uuid'],
        ]);
        $companyId = $user->isSuperAdmin() ? ($filters['company_id'] ?? null) : $user->company_id;
        $entries = $this->scoped(FinancialJournalEntry::query(), $user)->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->with('createdBy')
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))
            ->when($filters['account_type'] ?? null, fn ($q, $type) => $q->where('account_type', $type))
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->orderByDesc('occurred_at')->paginate(30)->withQueryString();
        $companies = $user->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect();

        return view('financial.journal', ['entries' => $entries, 'companies' => $companies, 'selectedCompany' => $companyId]);
    }

    public function reports(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'cash_register_id' => ['nullable', 'uuid', 'exists:cash_registers,id'],
            'bank_account_id' => ['nullable', 'uuid', 'exists:bank_accounts,id'],
        ]);
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        $registerFilter = $filters['cash_register_id'] ?? null;
        $bankFilter = $filters['bank_account_id'] ?? null;
        $companyFilter = $user->isSuperAdmin() ? ($filters['company_id'] ?? null) : $user->company_id;
        $expenses = $this->scoped(Expense::query(), $user)->when($companyFilter, fn ($q) => $q->where('company_id', $companyFilter))
            ->where('status', 'posted')->whereBetween('expense_date', [$from, $to])
            ->when($registerFilter, fn ($q) => $q->whereHas('cashSession', fn ($session) => $session->where('cash_register_id', $registerFilter)))
            ->when($bankFilter, fn ($q) => $q->where('bank_account_id', $bankFilter));
        $payments = Payment::query()->with('paymentMethod')->where('status', 'posted')
            ->whereBetween('paid_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('company_id', $user->company_id))
            ->when($companyFilter, fn ($q) => $q->where('company_id', $companyFilter))
            ->when($registerFilter, fn ($q) => $q->whereHas('cashSession', fn ($session) => $session->where('cash_register_id', $registerFilter)))
            ->when($bankFilter, fn ($q) => $q->where('bank_account_id', $bankFilter));
        $accounts = $this->scoped(BankAccount::query(), $user)->when($companyFilter, fn ($q) => $q->where('company_id', $companyFilter))
            ->where('is_active', true)->when($bankFilter, fn ($q) => $q->whereKey($bankFilter))->get();
        $cashRegisters = $this->scoped(CashRegister::query(), $user)->when($companyFilter, fn ($q) => $q->where('company_id', $companyFilter))
            ->where('is_active', true)->when($registerFilter, fn ($q) => $q->whereKey($registerFilter))
            ->with(['sessions' => fn ($q) => $q->latest('opened_at')->limit(1)])->get();
        $totalExpenses = (clone $expenses)->sum('amount');
        $totalPayments = (clone $payments)->sum('amount');
        $invoices = $this->scoped(Invoice::query(), $user)->when($companyFilter, fn ($q) => $q->where('company_id', $companyFilter))->where('status', '!=', 'cancelled')->whereBetween('issue_date', [$from, $to]);
        $receivables = Receivable::query()->whereHas('invoice', fn ($q) => $q
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $user->company_id))
            ->when($companyFilter, fn ($query) => $query->where('company_id', $companyFilter)));
        $journal = $this->scoped(FinancialJournalEntry::query(), $user)->when($companyFilter, fn ($q) => $q->where('company_id', $companyFilter))
            ->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($registerFilter, fn ($q) => $q->where('account_type', 'cash')->where('account_id', $registerFilter))
            ->when($bankFilter, fn ($q) => $q->where('account_type', 'bank')->where('account_id', $bankFilter));
        $cashCents = 0;
        foreach ($cashRegisters as $register) {
            $session = $register->sessions->first();
            if ($session) {
                $balance = $session->status === 'open' ? app(CashSessionService::class)->snapshot($session)['expected_amount'] : ($session->closing_amount ?? $session->expected_amount ?? $session->opening_amount);
                $cashCents += CashAmount::toCents($balance);
            }
        }
        $cashTotal = CashAmount::fromCents($cashCents);
        $bankCents = 0;
        foreach ($accounts as $account) {
            $bankCents += CashAmount::toCents($account->balance());
        }
        $bankTotal = CashAmount::fromCents($bankCents);

        return view('financial.reports', [
            'from' => $from, 'to' => $to, 'totalExpenses' => $totalExpenses, 'totalPayments' => $totalPayments,
            'totalInvoiced' => $invoices->sum('total'), 'receivablesTotal' => $receivables->sum('balance_due'),
            'cashIn' => (clone $journal)->where('account_type', 'cash')->where('direction', 'in')->sum('amount'),
            'cashOut' => (clone $journal)->where('account_type', 'cash')->where('direction', 'out')->sum('amount'),
            'bankIn' => (clone $journal)->where('account_type', 'bank')->where('direction', 'in')->sum('amount'),
            'bankOut' => (clone $journal)->where('account_type', 'bank')->where('direction', 'out')->sum('amount'),
            'paymentGroups' => (clone $payments)->selectRaw('payment_method_id, SUM(amount) as total, COUNT(*) as operations')->groupBy('payment_method_id')->with('paymentMethod')->get(),
            'accounts' => $accounts, 'cashRegisters' => $cashRegisters, 'cashTotal' => $cashTotal, 'bankTotal' => $bankTotal,
            'expenseGroups' => (clone $expenses)->selectRaw('expense_category_id, SUM(amount) as total, COUNT(*) as operations')->groupBy('expense_category_id')->with('category')->get(),
            'journal' => $journal->count(), 'selectedRegister' => $registerFilter, 'selectedBank' => $bankFilter,
            'selectedCompany' => $companyFilter,
            'companies' => $user->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect(),
        ]);
    }
}
