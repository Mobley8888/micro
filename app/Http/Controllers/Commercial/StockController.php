<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('stock.view'), 403);

        $products = Product::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $stockableProducts = $products->filter(fn (Product $product): bool => $product->is_stockable === true);
        $replenishment = $stockableProducts->filter(fn (Product $product): bool => $product->stockState() === 'RÉAPPROVISIONNEMENT')->count();
        $outOfStock = $stockableProducts->filter(fn (Product $product): bool => $product->stockState() === 'RUPTURE')->count();
        $uninitialized = $stockableProducts->filter(fn (Product $product): bool => $product->stockState() === 'NON INITIALISÉ')->count();

        return view('stock.index', [
            'products' => $products,
            'stats' => [
                'stockable' => $stockableProducts->count(),
                'replenishment' => $replenishment,
                'out_of_stock' => $outOfStock,
                'uninitialized' => $uninitialized,
            ],
        ]);
    }
}
