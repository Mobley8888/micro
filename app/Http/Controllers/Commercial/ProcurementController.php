<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Commercial\PurchaseOrderService;
use App\Services\Commercial\Stock\StockValuationService;
use App\Services\Stock\StockService;
use App\Support\CashAmount;
use App\Support\StockQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcurementController extends Controller
{
    public function suppliers(Request $request): View
    {
        $companyId = $this->companyId();
        $suppliers = Supplier::query()->where('company_id', $companyId)
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($q) => $q->where('name', 'ilike', '%'.$request->string('q').'%')->orWhere('code', 'ilike', '%'.$request->string('q').'%')->orWhere('phone', 'ilike', '%'.$request->string('q').'%')->orWhere('email', 'ilike', '%'.$request->string('q').'%')))
            ->withCount('purchaseOrders')->orderBy('name')->paginate(15)->withQueryString();

        return view('procurement.suppliers.index', compact('suppliers'));
    }

    public function supplierSearch(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = $request->string('q')->toString();
        $suppliers = Supplier::query()->where('company_id', $this->companyId())->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'ilike', "%{$term}%")->orWhere('trade_name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%")->orWhere('phone', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%"))
            ->limit(10)->get(['id', 'code', 'name', 'trade_name', 'phone', 'email']);

        return response()->json($suppliers);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('suppliers.create'), 403);
        $data = $request->validate(['code' => ['required', 'string', 'max:100', 'unique:suppliers,code,NULL,id,company_id,'.$this->companyId()], 'name' => ['required', 'string', 'max:255'], 'trade_name' => ['nullable', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string'], 'city' => ['nullable', 'string', 'max:255'], 'country' => ['nullable', 'string', 'max:100'], 'tax_number' => ['nullable', 'string', 'max:100'], 'registration_number' => ['nullable', 'string', 'max:100'], 'payment_terms' => ['nullable', 'string', 'max:255'], 'payment_delay_days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string']]);
        Supplier::query()->create($data + ['company_id' => $this->companyId(), 'created_by' => $request->user()->id]);

        return redirect()->route('suppliers.index')->with('success', 'Fournisseur créé.');
    }

    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        abort_unless($supplier->company_id === $this->companyId(), 404);
        abort_unless($request->user()->hasPermission('suppliers.update'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'trade_name' => ['nullable', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string'], 'city' => ['nullable', 'string', 'max:255'], 'country' => ['nullable', 'string', 'max:100'], 'tax_number' => ['nullable', 'string', 'max:100'], 'registration_number' => ['nullable', 'string', 'max:100'], 'payment_terms' => ['nullable', 'string', 'max:255'], 'payment_delay_days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string'], 'is_active' => ['required', 'boolean']]);
        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('success', 'Fournisseur mis à jour.');
    }

    public function purchases(): View
    {
        abort_unless(auth()->user()->hasPermission('purchases.view'), 403);
        $orders = PurchaseOrder::query()->where('company_id', $this->companyId())->with('supplier')->withCount('receipts')->latest('ordered_at')->paginate(15);

        return view('procurement.purchases.index', compact('orders'));
    }

    public function createPurchase(): View
    {
        abort_unless(auth()->user()->hasPermission('purchases.create'), 403);

        return view('procurement.purchases.create', [
            'suppliers' => Supplier::query()->where('company_id', $this->companyId())->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::query()->where('company_id', $this->companyId())->where('type', 'product')->where('is_stockable', true)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storePurchase(Request $request, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'uuid'], 'ordered_at' => ['required', 'date'], 'expected_at' => ['nullable', 'date', 'after_or_equal:ordered_at'], 'notes' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'uuid'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,4'], 'items.*.unit' => ['nullable', 'string', 'max:50']]);
        $order = $service->create($request->user(), $data);

        return redirect()->route('purchases.show', $order)->with('success', 'Commande fournisseur créée.');
    }

    public function showPurchase(PurchaseOrder $purchase): View
    {
        abort_unless($purchase->company_id === $this->companyId(), 404);
        abort_unless(auth()->user()->hasPermission('purchases.view'), 403);
        $purchase->load(['supplier', 'items.product', 'receipts.receivedBy', 'receipts.items.product']);

        return view('procurement.purchases.show', compact('purchase'));
    }

    public function confirmPurchase(PurchaseOrder $purchase, PurchaseOrderService $service): RedirectResponse
    {
        $service->confirm(auth()->user(), $purchase);

        return back()->with('success', 'Commande confirmée.');
    }

    public function receivePurchase(Request $request, PurchaseOrder $purchase, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate(['quantities' => ['required', 'array'], 'quantities.*' => ['required', 'numeric', 'min:0', 'max:100000000', 'decimal:0,3']]);
        $receipt = $service->receive($request->user(), $purchase, $data['quantities']);

        return back()->with('success', 'Réception '.$receipt->number.' validée et entrée en stock enregistrée.');
    }

    public function stock(Request $request, StockValuationService $valuation): View
    {
        abort_unless($request->user()->hasPermission('stock.view'), 403);
        $products = Product::query()->where('company_id', $this->companyId())->where('type', 'product')->where('is_stockable', true)->orderBy('name')->paginate(20);
        $rows = $products->getCollection()->map(function (Product $product) use ($valuation): array {
            $balance = $valuation->balance($product);

            return ['product' => $product, 'quantity' => StockQuantity::fromMilli($balance['quantity_milli']), 'value' => CashAmount::fromCents($balance['value_cents']), 'unit_cost' => StockQuantity::costFromScale($balance['unit_cost_scaled'])];
        });
        $products->setCollection(collect($rows));

        return view('procurement.stock.index', compact('products'));
    }

    public function openingBalance(Request $request, Product $product, StockService $service): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('stock.create'), 403);
        abort_unless($product->company_id === $this->companyId(), 404);
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $service->recordEntry(
            product: $product,
            quantity: (string) $data['quantity'],
            actor: $request->user(),
            reference: $data['reference'] ?? null,
            unitCost: (string) $data['unit_cost'],
            idempotencyKey: $data['idempotency_key'] ?? null,
            type: 'opening_balance',
        );

        return back()->with('success', 'Stock d’ouverture enregistré sous forme de mouvement.');
    }

    public function movements(Request $request): View
    {
        abort_unless($request->user()->hasPermission('stock.view'), 403);
        $movements = StockMovement::query()->where('company_id', $this->companyId())->with(['product', 'warehouse', 'createdBy'])->latest('occurred_at')->paginate(30);

        return view('procurement.stock.movements', compact('movements'));
    }

    private function companyId(): string
    {
        abort_unless(auth()->user()?->company_id !== null, 404);

        return (string) auth()->user()->company_id;
    }
}
