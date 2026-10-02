<?php

namespace App\Http\Controllers\Commercial;

use App\Exports\QuoteExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreQuoteRequest;
use App\Http\Requests\Commercial\UpdateQuoteRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Commercial\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuoteController extends Controller
{
    public function index(Request $request): View
    {
        $quotes = Quote::query()->where('company_id', $this->currentCompanyId())
            ->with('customer')
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

        return view('quotes.index', compact('quotes'));
    }

    public function create(): View
    {
        $products = Product::query()->with('tax')->where('company_id', $this->currentCompanyId())->where('is_active', true)->orderBy('name')->get();

        return view('quotes.create', [
            'customers' => Customer::query()->where('company_id', $this->currentCompanyId())->where('status', 'active')->orderBy('legal_name')->orderBy('last_name')->get(),
            'products' => $products,
            'productOptions' => $products->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'price' => (float) $product->sale_price, 'tax' => (float) ($product->tax?->rate ?? 0)])->values(),
        ]);
    }

    public function store(StoreQuoteRequest $request, QuoteService $quoteService): RedirectResponse
    {
        $validated = $request->validated();
        $quote = $quoteService->create($validated, $validated['lines']);

        return redirect()->route('quotes.show', $quote)->with('success', 'Devis créé avec succès.');
    }

    public function show(Quote $quote): View
    {
        abort_unless($quote->company_id === $this->currentCompanyId(), 404);
        $quote->load(['customer', 'items.product', 'invoice']);

        return view('quotes.show', compact('quote'));
    }

    public function exportExcel(Quote $quote): BinaryFileResponse
    {
        abort_unless($quote->company_id === $this->currentCompanyId(), 404);
        $quote->load(['company', 'customer', 'items.product']);

        return Excel::download(new QuoteExport($quote), 'devis-'.$quote->number.'.xlsx');
    }

    private function ensureCompanyOwnsQuote(Quote $quote): void
    {
        abort_unless($quote->company_id === $this->currentCompanyId(), 404);
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }

    public function edit(Quote $quote): View
    {
        abort_unless($quote->company_id === $this->currentCompanyId(), 404);
        abort_unless($quote->status === 'draft', 422, 'Seul un devis brouillon peut être modifié.');
        $products = Product::query()->with('tax')->where('company_id', $this->currentCompanyId())->where('is_active', true)->orderBy('name')->get();

        return view('quotes.edit', [
            'quote' => $quote->load('items'),
            'customers' => Customer::query()->where('company_id', $this->currentCompanyId())->where('status', 'active')->orderBy('legal_name')->orderBy('last_name')->get(),
            'products' => $products,
            'productOptions' => $products->map(fn (Product $product): array => ['id' => $product->id, 'name' => $product->name, 'description' => $product->description, 'price' => (float) $product->sale_price, 'tax' => (float) ($product->tax?->rate ?? 0)])->values(),
        ]);
    }

    public function update(UpdateQuoteRequest $request, Quote $quote, QuoteService $quoteService): RedirectResponse
    {
        $this->ensureCompanyOwnsQuote($quote);
        $validated = $request->validated();
        $quoteService->update($quote, $validated, $validated['lines']);

        return redirect()->route('quotes.show', $quote)->with('success', 'Devis mis à jour.');
    }

    public function destroy(Quote $quote, QuoteService $quoteService): RedirectResponse
    {
        $this->ensureCompanyOwnsQuote($quote);
        $quoteService->cancel($quote);

        return redirect()->route('quotes.index')->with('success', 'Devis annulé.');
    }

    public function send(Quote $quote, QuoteService $quoteService): RedirectResponse
    {
        $this->ensureCompanyOwnsQuote($quote);
        $quoteService->changeStatus($quote, 'sent');

        return redirect()->back()->with('success', 'Devis envoyé.');
    }

    public function accept(Quote $quote, QuoteService $quoteService): RedirectResponse
    {
        $this->ensureCompanyOwnsQuote($quote);
        $quoteService->changeStatus($quote, 'accepted');

        return redirect()->back()->with('success', 'Devis accepté.');
    }

    public function reject(Quote $quote, QuoteService $quoteService): RedirectResponse
    {
        $this->ensureCompanyOwnsQuote($quote);
        $quoteService->changeStatus($quote, 'rejected');

        return redirect()->back()->with('success', 'Devis refusé.');
    }
}
