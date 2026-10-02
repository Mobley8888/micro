<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\Warehouse;
use App\Services\Commercial\InventoryCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryCountController extends Controller
{
    public function __construct(private readonly InventoryCountService $inventoryCountService) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('stock.view'), 403);
        abort_unless($request->user()->company_id !== null, 404);

        $inventories = InventoryCount::query()
            ->where('company_id', $request->user()->company_id)
            ->with(['warehouse', 'createdBy', 'validatedBy'])
            ->withCount('items')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('stock.inventories.index', compact('inventories'));
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->hasPermission('stock.inventory'), 403);
        abort_unless($request->user()->company_id !== null, 404);

        $warehouses = Warehouse::query()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('stock.inventories.create', compact('warehouses'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('stock.inventory'), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $inventoryCount = $this->inventoryCountService->create(
            $request->user(),
            $data['warehouse_id'],
            $data['notes'] ?? null,
        );

        return redirect()->route('stock.inventories.show', $inventoryCount)->with('success', 'Inventaire créé avec le stock théorique capturé.');
    }

    public function show(Request $request, InventoryCount $inventoryCount): View
    {
        $inventoryCount = $this->inventoryCountService->findForUser($request->user(), $inventoryCount);

        return view('stock.inventories.show', compact('inventoryCount'));
    }

    public function recordCounts(Request $request, InventoryCount $inventoryCount): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('stock.inventory'), 403);
        $data = $request->validate([
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'decimal:0,3'],
            'item_notes' => ['sometimes', 'array'],
            'item_notes.*' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->inventoryCountService->recordCounts(
            $request->user(),
            $inventoryCount,
            $data['counts'],
            $data['item_notes'] ?? [],
            $data['notes'] ?? null,
        );

        return back()->with('success', 'Comptage enregistré.');
    }

    public function validateInventory(Request $request, InventoryCount $inventoryCount): RedirectResponse
    {
        $validated = $this->inventoryCountService->validateCount($request->user(), $inventoryCount);

        return redirect()->route('stock.inventories.show', $validated)->with('success', 'Inventaire validé et écarts régularisés.');
    }
}
