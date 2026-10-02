<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Stock\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('stock.view'), 403);

        $query = StockMovement::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['product', 'warehouse', 'createdBy'])
            ->latest('occurred_at');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->filled('q')) {
            $search = '%'.$request->q.'%';
            $query->where(function ($inner) use ($search): void {
                $inner->where('reference', 'like', $search)
                    ->orWhereRelation('product', 'name', 'like', $search)
                    ->orWhereRelation('product', 'code', 'like', $search)
                    ->orWhereRelation('warehouse', 'name', 'like', $search);
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('occurred_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('occurred_at', '<=', $request->to_date);
        }

        $movements = $query->paginate(15)->appends($request->query());

        return view('stock.movements.index', [
            'movements' => $movements,
            'products' => Product::query()->where('company_id', $request->user()->company_id)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('company_id', $request->user()->company_id)->orderBy('name')->get(),
            'filters' => $request->only(['product_id', 'warehouse_id', 'type', 'direction', 'q', 'from_date', 'to_date']),
        ]);
    }

    public function show(Request $request, StockMovement $stockMovement): View
    {
        abort_unless($request->user()?->hasPermission('stock.view'), 403);

        $stockMovement->load(['product', 'warehouse', 'createdBy', 'allocations.lot']);
        abort_unless($stockMovement->company_id === $request->user()->company_id, 404);

        return view('stock.movements.show', ['movement' => $stockMovement]);
    }

    public function createEntry(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('stock.create'), 403);

        return view('stock.movements.create-entry', [
            'products' => Product::query()->where('company_id', $request->user()->company_id)->where('type', 'product')->where('is_stockable', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('company_id', $request->user()->company_id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeEntry(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('stock.create'), 403);

        try {
            $data = $this->validateMovement($request, 'entry');
            $product = Product::query()->whereKey($data['product_id'])->where('company_id', $request->user()->company_id)->firstOrFail();
            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->where('company_id', $request->user()->company_id)->firstOrFail();

            $this->stockService->recordEntry(
                product: $product,
                quantity: $data['quantity'],
                actor: $request->user(),
                warehouse: $warehouse,
                reference: $data['reference'] ?? null,
                unitCost: $data['unit_cost'] ?? null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                occurredAt: $data['occurred_at'] ?? null,
            );

            return redirect()->route('stock.movements.index')->with('success', 'Entrée de stock enregistrée.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    public function createExit(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('stock.create'), 403);

        return view('stock.movements.create-exit', [
            'products' => Product::query()->where('company_id', $request->user()->company_id)->where('type', 'product')->where('is_stockable', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('company_id', $request->user()->company_id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeExit(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('stock.create'), 403);

        try {
            $data = $this->validateMovement($request, 'exit');
            $product = Product::query()->whereKey($data['product_id'])->where('company_id', $request->user()->company_id)->firstOrFail();
            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->where('company_id', $request->user()->company_id)->firstOrFail();

            $this->stockService->recordExit(
                product: $product,
                quantity: $data['quantity'],
                actor: $request->user(),
                warehouse: $warehouse,
                reference: $data['reference'] ?? null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                occurredAt: $data['occurred_at'] ?? null,
            );

            return redirect()->route('stock.movements.index')->with('success', 'Sortie de stock enregistrée.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    public function createAdjustment(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('stock.adjust'), 403);

        return view('stock.movements.create-adjustment', [
            'products' => Product::query()->where('company_id', $request->user()->company_id)->where('type', 'product')->where('is_stockable', true)->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('company_id', $request->user()->company_id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('stock.adjust'), 403);

        try {
            $data = $this->validateAdjustment($request);
            $product = Product::query()->whereKey($data['product_id'])->where('company_id', $request->user()->company_id)->firstOrFail();
            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->where('company_id', $request->user()->company_id)->firstOrFail();

            $this->stockService->adjustStock(
                product: $product,
                delta: $data['delta'],
                actor: $request->user(),
                warehouse: $warehouse,
                reference: $data['reference'] ?? null,
                occurredAt: $data['occurred_at'] ?? null,
                reason: $data['reason'],
            );

            return redirect()->route('stock.movements.index')->with('success', 'Ajustement de stock enregistré.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    private function validateMovement(Request $request, string $kind): array
    {
        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'uuid'],
        ];

        if ($kind === 'entry') {
            $rules['unit_cost'] = ['required', 'numeric', 'min:0'];
        }

        $data = $request->validate($rules);

        $product = Product::query()->whereKey($data['product_id'])->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->firstOrFail();

        abort_unless($product->company_id === $request->user()->company_id, 404);
        abort_unless($warehouse->company_id === $request->user()->company_id, 404);
        abort_unless($product->type === 'product' && $product->is_stockable, 422);

        return $data;
    }

    private function validateAdjustment(Request $request): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'delta' => ['required', 'numeric'],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $product = Product::query()->whereKey($data['product_id'])->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->firstOrFail();

        abort_unless($product->company_id === $request->user()->company_id, 404);
        abort_unless($warehouse->company_id === $request->user()->company_id, 404);
        abort_unless($product->type === 'product' && $product->is_stockable, 422);

        return $data;
    }
}
