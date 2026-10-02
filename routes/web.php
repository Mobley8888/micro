<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\UserAdministrationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Commercial\CashRegisterController;
use App\Http\Controllers\Commercial\CashSessionController;
use App\Http\Controllers\Commercial\FinancialOperationsController;
use App\Http\Controllers\Commercial\InventoryCountController;
use App\Http\Controllers\Commercial\InvoiceController;
use App\Http\Controllers\Commercial\OperatorWorkspaceController;
use App\Http\Controllers\Commercial\PaymentController;
use App\Http\Controllers\Commercial\ProcurementController;
use App\Http\Controllers\Commercial\ProductController;
use App\Http\Controllers\Commercial\QuoteController;
use App\Http\Controllers\Commercial\ReceiptController;
use App\Http\Controllers\Commercial\ReceivableController;
use App\Http\Controllers\Commercial\StockController;
use App\Http\Controllers\Commercial\StockMovementController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\LeadController;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Commercial\CashSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});
Route::middleware(['auth', 'account.active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', function (Request $request, CashSessionService $cashSessionService) {
        $user = auth()->user();
        if ($user->isSuperAdmin() || $user->isCompanyAdmin()) {
            return redirect()->route('admin.dashboard');
        }
        $companyId = $user->company_id;
        abort_unless($companyId !== null, 404);
        $companyInvoiceIds = Invoice::query()->where('company_id', $companyId)->where('status', '!=', 'cancelled')->select('id');

        $invoices = Invoice::query()->where('company_id', $companyId)->where('status', '!=', 'cancelled');
        $payments = Payment::query()->where('company_id', $companyId)->where('status', 'posted');
        $receivables = Receivable::query()->whereIn('invoice_id', $companyInvoiceIds);

        $myCashSession = CashSession::query()->where('company_id', $companyId)->where('opened_by', $user->id)
            ->where('status', CashSession::STATUS_OPEN)->with('cashRegister')->first();
        $cashSnapshot = $myCashSession ? $cashSessionService->snapshot($myCashSession) : null;
        $activeCashRegisters = $user->hasPermission('cash.open')
            ? CashRegister::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get()
            : collect();
        $todayInvoices = (clone $invoices)->whereDate('issue_date', today());
        $todayPayments = (clone $payments)->whereDate('paid_at', today());
        $recentInvoices = (clone $invoices)->with('customer')->latest('issue_date')->limit(5)->get();
        $stockableProducts = Product::query()->where('company_id', $companyId)->where('is_stockable', true)->get();

        return view('dashboard.index', ['operator' => $user, 'myCashSession' => $myCashSession, 'cashSnapshot' => $cashSnapshot, 'activeCashRegisters' => $activeCashRegisters, 'recentInvoices' => $recentInvoices, 'stats' => [
            'customers' => Customer::query()->where('company_id', $companyId)->where('status', 'active')->count(),
            'today_invoiced' => (clone $todayInvoices)->sum('total'),
            'today_collected' => (clone $todayPayments)->sum('amount'),
            'today_invoices' => (clone $todayInvoices)->count(),
            'invoices' => (clone $invoices)->count(),
            'invoiced' => (clone $invoices)->sum('total'),
            'collected' => (clone $payments)->sum('amount'),
            'receivables' => (clone $receivables)->sum('balance_due'),
            'overdue' => (clone $receivables)->where('balance_due', '>', 0)->whereDate('due_date', '<', today())->sum('balance_due'),
            'unpaid_count' => (clone $invoices)->where('financial_status', 'unpaid')->count(),
            'partial_count' => (clone $invoices)->where('financial_status', 'partially_paid')->count(),
            'overdue_count' => (clone $receivables)->where('balance_due', '>', 0)->whereDate('due_date', '<', today())->count(),
            'stockable' => $stockableProducts->count(),
            'replenishment' => $stockableProducts->filter(fn (Product $product): bool => $product->stockState() === 'RÉAPPROVISIONNEMENT')->count(),
            'out_of_stock' => $stockableProducts->filter(fn (Product $product): bool => $product->stockState() === 'RUPTURE')->count(),
            'uninitialized' => $stockableProducts->filter(fn (Product $product): bool => $product->stockState() === 'NON INITIALISÉ')->count(),
        ]]);
    })->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('/admin', function () {
        $user = auth()->user();
        $companyId = $user->company_id;

        $financialInvoices = Invoice::query()->where('status', '!=', 'cancelled')->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $companyId));
        $financialPayments = Payment::query()->where('status', 'posted')->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $companyId));
        $financialReceivables = Receivable::query()->whereHas('invoice', fn ($query) => $query->when(! $user->isSuperAdmin(), fn ($invoice) => $invoice->where('company_id', $companyId)));

        return view('admin.dashboard', [
            'financialStats' => [
                'invoiced' => (clone $financialInvoices)->sum('total'),
                'collected' => (clone $financialPayments)->sum('amount'),
                'balance' => (clone $financialReceivables)->sum('balance_due'),
                'overdue' => (clone $financialReceivables)->where('balance_due', '>', 0)->whereDate('due_date', '<', today())->sum('balance_due'),
                'unpaid' => (clone $financialInvoices)->where('financial_status', 'unpaid')->count(),
                'partial' => (clone $financialInvoices)->where('financial_status', 'partially_paid')->count(),
                'overdueCount' => (clone $financialReceivables)->where('balance_due', '>', 0)->whereDate('due_date', '<', today())->count(),
            ],
            'companiesCount' => $user->isSuperAdmin() ? Company::query()->count() : null,
            'usersCount' => $user->isSuperAdmin()
                ? User::query()->count()
                : User::query()->where('company_id', $companyId)->count(),
            'activeUsersCount' => $user->isSuperAdmin()
                ? User::query()->where('is_active', true)->count()
                : User::query()->where('company_id', $companyId)->where('is_active', true)->count(),
            'inactiveUsersCount' => $user->isSuperAdmin()
                ? User::query()->where('is_active', false)->count()
                : User::query()->where('company_id', $companyId)->where('is_active', false)->count(),
        ]);
    })->middleware('role:super_admin,company_admin')->name('admin.dashboard');

    Route::middleware('role:super_admin')->prefix('admin/companies')->name('admin.companies.')->group(function (): void {
        Route::get('/', [CompanyController::class, 'index'])->name('index');
        Route::get('/create', [CompanyController::class, 'create'])->name('create');
        Route::post('/', [CompanyController::class, 'store'])->name('store');
        Route::get('/{company}', [CompanyController::class, 'show'])->name('show');
        Route::get('/{company}/edit', [CompanyController::class, 'edit'])->name('edit');
        Route::put('/{company}', [CompanyController::class, 'update'])->name('update');
        Route::patch('/{company}/activate', [CompanyController::class, 'activate'])->name('activate');
        Route::patch('/{company}/deactivate', [CompanyController::class, 'deactivate'])->name('deactivate');
    });

    Route::middleware('role:super_admin')->prefix('admin/users')->name('admin.users.')->group(function (): void {
        Route::get('/', [UserAdministrationController::class, 'index'])->name('index');
        Route::get('/create', [UserAdministrationController::class, 'create'])->name('create');
        Route::post('/', [UserAdministrationController::class, 'store'])->name('store');
        Route::get('/{user}', [UserAdministrationController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserAdministrationController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserAdministrationController::class, 'update'])->name('update');
        Route::patch('/{user}/activate', [UserAdministrationController::class, 'activate'])->name('activate');
        Route::patch('/{user}/deactivate', [UserAdministrationController::class, 'deactivate'])->name('deactivate');
    });

    Route::middleware('role:company_admin')->prefix('users')->name('users.')->group(function (): void {
        Route::get('/', [UserAdministrationController::class, 'index'])->name('index');
        Route::get('/create', [UserAdministrationController::class, 'create'])->name('create');
        Route::post('/', [UserAdministrationController::class, 'store'])->name('store');
        Route::get('/{user}', [UserAdministrationController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserAdministrationController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserAdministrationController::class, 'update'])->name('update');
        Route::patch('/{user}/activate', [UserAdministrationController::class, 'activate'])->name('activate');
        Route::patch('/{user}/deactivate', [UserAdministrationController::class, 'deactivate'])->name('deactivate');
    });

    Route::get('/stock', [StockController::class, 'index'])->middleware('permission:stock.view')->name('stock.index');
    Route::prefix('stock/inventories')->name('stock.inventories.')->group(function (): void {
        Route::get('/', [InventoryCountController::class, 'index'])->middleware('permission:stock.view')->name('index');
        Route::get('/create', [InventoryCountController::class, 'create'])->middleware('permission:stock.inventory')->name('create');
        Route::post('/', [InventoryCountController::class, 'store'])->middleware('permission:stock.inventory')->name('store');
        Route::get('/{inventoryCount}', [InventoryCountController::class, 'show'])->middleware('permission:stock.view')->name('show');
        Route::patch('/{inventoryCount}/counts', [InventoryCountController::class, 'recordCounts'])->middleware('permission:stock.inventory')->name('counts');
        Route::post('/{inventoryCount}/validate', [InventoryCountController::class, 'validateInventory'])->middleware('permission:stock.validate')->name('validate');
    });
    Route::prefix('stock/movements')->name('stock.movements.')->middleware('permission:stock.view')->group(function (): void {
        Route::get('/', [StockMovementController::class, 'index'])->name('index');
        Route::get('/{stockMovement}', [StockMovementController::class, 'show'])->name('show');
        Route::get('/entries/create', [StockMovementController::class, 'createEntry'])->middleware('permission:stock.create')->name('entry.create');
        Route::post('/entries', [StockMovementController::class, 'storeEntry'])->middleware('permission:stock.create')->name('entry.store');
        Route::get('/exits/create', [StockMovementController::class, 'createExit'])->middleware('permission:stock.create')->name('exit.create');
        Route::post('/exits', [StockMovementController::class, 'storeExit'])->middleware('permission:stock.create')->name('exit.store');
        Route::get('/adjustments/create', [StockMovementController::class, 'createAdjustment'])->middleware('permission:stock.adjust')->name('adjustment.create');
        Route::post('/adjustments', [StockMovementController::class, 'storeAdjustment'])->middleware('permission:stock.adjust')->name('adjustment.store');
    });
    Route::get('/suppliers', [ProcurementController::class, 'suppliers'])->middleware('permission:suppliers.view')->name('suppliers.index');
    Route::post('/suppliers', [ProcurementController::class, 'storeSupplier'])->middleware('permission:suppliers.create')->name('suppliers.store');
    Route::put('/suppliers/{supplier}', [ProcurementController::class, 'updateSupplier'])->middleware('permission:suppliers.update')->name('suppliers.update');
    Route::prefix('purchases')->name('purchases.')->group(function (): void {
        Route::get('/', [ProcurementController::class, 'purchases'])->middleware('permission:purchases.view')->name('index');
        Route::get('/create', [ProcurementController::class, 'createPurchase'])->middleware('permission:purchases.create')->name('create');
        Route::post('/', [ProcurementController::class, 'storePurchase'])->middleware('permission:purchases.create')->name('store');
        Route::get('/{purchase}', [ProcurementController::class, 'showPurchase'])->middleware('permission:purchases.view')->name('show');
        Route::post('/{purchase}/confirm', [ProcurementController::class, 'confirmPurchase'])->middleware('permission:purchases.confirm')->name('confirm');
        Route::post('/{purchase}/receive', [ProcurementController::class, 'receivePurchase'])->middleware('permission:purchases.receive')->name('receive');
    });
    Route::get('/customers/search', [OperatorWorkspaceController::class, 'searchCustomers'])->middleware('permission:customer.view')->name('customers.search');
    Route::post('/customers/quick-store', [OperatorWorkspaceController::class, 'quickStoreCustomer'])->middleware('permission:customer.create')->name('customers.quick-store');
    Route::get('/products/search', [OperatorWorkspaceController::class, 'searchProducts'])->middleware('permission:invoice.create')->name('products.search');
    Route::prefix('customers')->name('customers.')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customer.view')->name('index');
        Route::get('/create', [CustomerController::class, 'create'])->middleware('permission:customer.create')->name('create');
        Route::post('/', [CustomerController::class, 'store'])->middleware('permission:customer.create')->name('store');
        Route::get('/{customer}', [CustomerController::class, 'show'])->middleware('permission:customer.view')->name('show');
        Route::get('/{customer}/edit', [CustomerController::class, 'edit'])->middleware('permission:customers.manage')->name('edit');
        Route::put('/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.manage')->name('update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.manage')->name('destroy');
    });
    Route::resource('leads', LeadController::class)->except(['update'])->names('leads')->middleware('permission:leads.manage');
    Route::prefix('products')->name('products.')->middleware('permission:products.manage')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->name('edit');
        Route::put('/{product}', [ProductController::class, 'update'])->name('update');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
        Route::patch('/{product}/activate', [ProductController::class, 'activate'])->name('activate');
        Route::patch('/{product}/deactivate', [ProductController::class, 'deactivate'])->name('deactivate');
    });
    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->middleware('permission:products.manage|stock.view')
        ->name('products.show');
    Route::prefix('quotes')->name('quotes.')->middleware('permission:quotes.manage')->group(function (): void {
        Route::get('/', [QuoteController::class, 'index'])->name('index');
        Route::get('/create', [QuoteController::class, 'create'])->name('create');
        Route::post('/', [QuoteController::class, 'store'])->name('store');
        Route::get('/{quote}/export/excel', [QuoteController::class, 'exportExcel'])->name('export.excel');
        Route::get('/{quote}', [QuoteController::class, 'show'])->name('show');
        Route::get('/{quote}/edit', [QuoteController::class, 'edit'])->name('edit');
        Route::put('/{quote}', [QuoteController::class, 'update'])->name('update');
        Route::delete('/{quote}', [QuoteController::class, 'destroy'])->name('destroy');
        Route::patch('/{quote}/send', [QuoteController::class, 'send'])->name('send');
        Route::patch('/{quote}/accept', [QuoteController::class, 'accept'])->name('accept');
        Route::patch('/{quote}/reject', [QuoteController::class, 'reject'])->name('reject');
        Route::post('/{quote}/convert-to-invoice', [InvoiceController::class, 'convertFromQuote'])->name('convert-to-invoice');
    });
    Route::get('/cash/my-session', [OperatorWorkspaceController::class, 'mySession'])->middleware('permission:cash.view')->name('cash.my-session');
    Route::post('/cash/my-session/prepare', [OperatorWorkspaceController::class, 'prepareInvoiceRedirect'])->middleware('permission:cash.open')->name('cash.my-session.prepare');
    Route::post('/cash/my-session/open', [OperatorWorkspaceController::class, 'openMySession'])->middleware('permission:cash.open')->name('cash.my-session.open');
    Route::prefix('caisses')->name('cash-registers.')->group(function (): void {
        Route::get('/', [CashRegisterController::class, 'index'])->middleware('permission:cash.view')->name('index');
        Route::get('/create', [CashRegisterController::class, 'create'])->middleware('permission:cash.create')->name('create');
        Route::post('/', [CashRegisterController::class, 'store'])->middleware('permission:cash.create')->name('store');
        Route::get('/{cashRegister}', [CashRegisterController::class, 'show'])->middleware('permission:cash.view')->name('show');
        Route::get('/{cashRegister}/edit', [CashRegisterController::class, 'edit'])->middleware('permission:cash.create')->name('edit');
        Route::put('/{cashRegister}', [CashRegisterController::class, 'update'])->middleware('permission:cash.create')->name('update');
        Route::patch('/{cashRegister}/activate', [CashRegisterController::class, 'activate'])->middleware('permission:cash.create')->name('activate');
        Route::patch('/{cashRegister}/deactivate', [CashRegisterController::class, 'deactivate'])->middleware('permission:cash.create')->name('deactivate');
        Route::post('/{cashRegister}/sessions', [CashSessionController::class, 'open'])->middleware('permission:cash.open')->name('sessions.open');
        Route::post('/{cashRegister}/sessions/{cashSession}/movements', [CashSessionController::class, 'movement'])->middleware('permission:cash.move')->name('sessions.movements.store');
        Route::post('/{cashRegister}/sessions/{cashSession}/close', [CashSessionController::class, 'close'])->middleware('permission:cash.close')->name('sessions.close');
    });
    Route::get('/cash-movements/{cashMovement}/attachment', [CashSessionController::class, 'attachment'])->middleware('permission:cash.view')->name('cash-movements.attachment');
    Route::get('/expenses', [FinancialOperationsController::class, 'expenses'])->middleware('permission:expense.view')->name('expenses.index');
    Route::get('/expenses/create', [FinancialOperationsController::class, 'createExpense'])->middleware('permission:cash.expense')->name('expenses.create');
    Route::post('/expenses', [FinancialOperationsController::class, 'storeExpense'])->middleware('permission:cash.expense')->name('expenses.store');
    Route::post('/expenses/{expense}/cancel', [FinancialOperationsController::class, 'cancelExpense'])->middleware('permission:expense.cancel')->name('expenses.cancel');
    Route::get('/expenses/{expense}/attachment', [FinancialOperationsController::class, 'attachment'])->middleware('permission:expense.view')->name('expenses.attachment');
    Route::post('/expense-categories', [FinancialOperationsController::class, 'storeCategory'])->middleware('permission:expense.create')->name('expense-categories.store');
    Route::get('/banks', [FinancialOperationsController::class, 'banks'])->middleware('permission:bank.view')->name('banks.index');
    Route::post('/banks', [FinancialOperationsController::class, 'storeBank'])->middleware('permission:bank.create')->name('banks.store');
    Route::get('/financial-transfers/create', [FinancialOperationsController::class, 'createTransfer'])->middleware('permission:bank.transaction')->name('financial-transfers.create');
    Route::get('/banks/{bankAccount}/transactions', [FinancialOperationsController::class, 'bankTransactions'])->middleware('permission:bank.view')->name('banks.transactions');
    Route::post('/banks/{bankAccount}/transactions', [FinancialOperationsController::class, 'storeBankTransaction'])->middleware('permission:bank.transaction')->name('banks.transactions.store');
    Route::patch('/bank-transactions/{bankTransaction}/reconcile', [FinancialOperationsController::class, 'reconcile'])->middleware('permission:bank.reconcile')->name('banks.reconcile');
    Route::post('/financial-transfers', [FinancialOperationsController::class, 'storeTransfer'])->middleware('permission:bank.transaction')->name('financial-transfers.store');
    Route::get('/financial-journal', [FinancialOperationsController::class, 'journal'])->middleware('permission:financial.journal.view')->name('financial.journal');
    Route::get('/financial-reports', [FinancialOperationsController::class, 'reports'])->middleware('permission:financial.report.view')->name('financial.reports');

    Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:payment.view')->name('payments.index');
    Route::get('/payments/{payment}/receipt', [ReceiptController::class, 'show'])->middleware('permission:receipt.view')->name('payments.receipt');
    Route::get('/receivables', [ReceivableController::class, 'index'])->middleware('permission:invoice.view')->name('receivables.index');
    Route::prefix('invoices')->name('invoices.')->group(function (): void {
        Route::get('/', [InvoiceController::class, 'index'])->middleware('permission:invoice.view')->name('index');
        Route::get('/create', [OperatorWorkspaceController::class, 'createInvoice'])->middleware('permission:invoice.create')->name('create');
        Route::post('/', [OperatorWorkspaceController::class, 'storeInvoice'])->middleware('permission:invoice.create')->name('store');
        Route::post('/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('permission:invoice.create')->name('issue');
        Route::post('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoice.create')->name('cancel');
        Route::get('/{invoice}/payments', [PaymentController::class, 'invoiceIndex'])->middleware('permission:payment.view')->name('payments.index');
        Route::post('/{invoice}/payments', [PaymentController::class, 'store'])->middleware('permission:payment.create')->name('payments.store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoice.view')->name('show');
    });
    Route::get('/{module}', fn (string $module) => view($module.'.index'))
        ->whereIn('module', ['receipts', 'reports', 'settings'])->name('page');
});
