<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Receivable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReceivableController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $companyId = $user->company_id;
        $receivables = Receivable::query()
            ->with(['invoice', 'customer'])
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice->where('company_id', $companyId)))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->string('customer_id')->toString()))
            ->when($request->filled('invoice'), fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice->where('number', 'ilike', '%'.$request->string('invoice')->toString().'%')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('invoice', fn ($invoice) => $invoice->where('number', 'ilike', "%{$search}%"))
                        ->orWhereHas('customer', fn ($customer) => $customer->where('legal_name', 'ilike', "%{$search}%")
                            ->orWhere('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%"));
                });
            })
            ->when($request->filled('period_from'), fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice->whereDate('issue_date', '>=', $request->date('period_from'))))
            ->when($request->filled('period_to'), fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice->whereDate('issue_date', '<=', $request->date('period_to'))))
            ->when($request->filled('due_from'), fn ($query) => $query->whereDate('due_date', '>=', $request->date('due_from')))
            ->when($request->filled('due_to'), fn ($query) => $query->whereDate('due_date', '<=', $request->date('due_to')))
            ->when($request->boolean('overdue_only') || $request->input('status') === 'overdue', fn ($query) => $query->where('balance_due', '>', 0)->whereDate('due_date', '<', today()))
            ->when(in_array($request->input('status'), ['unpaid', 'partially_paid', 'paid'], true), fn ($query) => $query->where('status', $request->input('status')))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        $customers = Customer::query()->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $companyId))->orderBy('legal_name')->orderBy('first_name')->get(['id', 'legal_name', 'first_name', 'last_name']);

        return view('receivables.index', compact('receivables', 'customers'));
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
