<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreProductRequest;
use App\Http\Requests\Commercial\UpdateProductRequest;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Services\Commercial\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()->where('company_id', $this->currentCompanyId())
            ->with('tax')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn ($query) => $query->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
            })
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->boolean('status')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('products.index', compact('products'));
    }

    public function create(): View
    {
        return view('products.create', ['taxes' => Tax::query()->where('company_id', $this->currentCompanyId())->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(StoreProductRequest $request, ProductService $productService): RedirectResponse
    {
        $productService->create($request->validated());

        return redirect()->route('products.index')->with('success', 'Produit ou service créé avec succès.');
    }

    public function show(Request $request, Product $product): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->ensureCompanyOwnsProduct($product);

        $canManageProducts = $user->hasPermission('products.manage');

        return view('products.show', [
            'product' => $product,
            'canManageProducts' => $canManageProducts,
            'canViewStock' => $user->hasPermission('stock.view'),
            'canViewStockValuation' => $user->hasPermission('stock.valuation.view') || $canManageProducts,
        ]);
    }

    public function edit(Product $product): View
    {
        $this->ensureCompanyOwnsProduct($product);

        return view('products.edit', [
            'product' => $product,
            'taxes' => Tax::query()->where('company_id', $this->currentCompanyId())->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductService $productService): RedirectResponse
    {
        $this->ensureCompanyOwnsProduct($product);
        $productService->update($product, $request->validated());

        return redirect()->route('products.show', $product)->with('success', 'Produit ou service mis à jour.');
    }

    public function destroy(Product $product, ProductService $productService): RedirectResponse
    {
        $this->ensureCompanyOwnsProduct($product);
        $productService->archive($product);

        return redirect()->route('products.index')->with('success', 'Produit ou service archivé.');
    }

    public function activate(Product $product, ProductService $productService): RedirectResponse
    {
        $this->ensureCompanyOwnsProduct($product);
        $productService->activate($product);

        return redirect()->back()->with('success', 'Produit ou service activé.');
    }

    public function deactivate(Product $product, ProductService $productService): RedirectResponse
    {
        $this->ensureCompanyOwnsProduct($product);
        $productService->deactivate($product);

        return redirect()->back()->with('success', 'Produit ou service désactivé.');
    }

    private function ensureCompanyOwnsProduct(Product $product): void
    {
        abort_unless($product->company_id === $this->currentCompanyId(), 404);
    }

    private function currentCompanyId(): string
    {
        $user = auth()->user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
