<?php

namespace App\Http\Controllers;

use App\CustomerService;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\View\View;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();

        return view('customers.index', ['customers' => Customer::query()->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $user->company_id))->latest()->paginate(15)]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('customers.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request, CustomerService $customerService)
    {
        $customerService->create($request->validated());

        return redirect()->route('customers.index')->with('success', 'Client créé avec succès.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer): View
    {
        $this->ensureCompanyOwnsCustomer($customer);
        $companyId = $customer->company_id;
        $customer->load(['contacts', 'interactions']);
        $invoices = Invoice::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->with('receivable')
            ->latest('issue_date')
            ->get();
        $payments = $customer->payments()
            ->where('company_id', $companyId)
            ->where('status', 'posted')
            ->get();
        $financial = [
            'invoiced' => $invoices->sum('total'),
            'collected' => $payments->sum('amount'),
            'balance' => $invoices->sum('balance_due'),
            'overdue' => $invoices->filter(fn (Invoice $invoice): bool => $invoice->daysOverdue() > 0)->sum('balance_due'),
        ];

        return view('customers.show', compact('customer', 'invoices', 'financial'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Customer $customer)
    {
        $this->ensureCompanyOwnsCustomer($customer);

        return view('customers.edit', compact('customer'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer, CustomerService $customerService)
    {
        $this->ensureCompanyOwnsCustomer($customer);
        $customerService->update($customer, $request->validated());

        return redirect()->route('customers.show', $customer)->with('success', 'Client mis à jour.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        $this->ensureCompanyOwnsCustomer($customer);
        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Client archivé.');
    }

    private function ensureCompanyOwnsCustomer(Customer $customer): void
    {
        abort_unless(auth()->user()->isSuperAdmin() || $customer->company_id === $this->currentCompanyId(), 404);
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
