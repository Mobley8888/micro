@extends('layouts.app', ['heading' => 'Gestion du stock', 'eyebrow' => 'Stock'])
@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <p class="section-kicker">Stock</p>
            <h2 class="hero-title">Gestion du stock</h2>
            <p class="muted-copy">Consultation du stock par produit, selon les données actuellement disponibles.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('stock.movements.index') }}" class="button-secondary">Historique des mouvements</a>
            <a href="{{ route('stock.inventories.index') }}" class="button-secondary">Inventaires</a>
            @if(auth()->user()->hasPermission('stock.inventory'))
                <a href="{{ route('stock.inventories.create') }}" class="button-primary">Nouvel inventaire</a>
            @endif
        </div>
    </div>

    <section class="stats-grid mb-8">
        <div class="stat-card accent-green">
            <p>Produits stockables</p>
            <strong>{{ $stats['stockable'] }}</strong>
        </div>
        <div class="stat-card accent-orange">
            <p>À réapprovisionner</p>
            <strong>{{ $stats['replenishment'] }}</strong>
        </div>
        <div class="stat-card accent-red">
            <p>En rupture</p>
            <strong>{{ $stats['out_of_stock'] }}</strong>
        </div>
        <div class="stat-card accent-neutral">
            <p>Stock non initialisé</p>
            <strong>{{ $stats['uninitialized'] }}</strong>
        </div>
    </section>

    <section class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Code</th>
                        <th>Stockable</th>
                        <th>Stock actuel</th>
                        <th>Minimum</th>
                        <th>Maximum</th>
                        <th>Seuil réappro.</th>
                        <th>État</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $product->name }}</div>
                            </td>
                            <td>{{ $product->code }}</td>
                            <td>{{ $product->is_stockable ? 'Oui' : 'Non' }}</td>
                            <td>
                                @if($product->is_stockable === false)
                                    <span class="status-pill status-neutral">Non stockable</span>
                                @elseif($product->currentStockQuantity() === null)
                                    <span class="status-pill status-neutral">Stock non initialisé</span>
                                @else
                                    {{ $product->stockDisplayLabel() }}
                                @endif
                            </td>
                            <td>{{ $product->stock_minimum !== null ? $product->formatStockMetric($product->stock_minimum) : '—' }}</td>
                            <td>{{ $product->stock_maximum !== null ? $product->formatStockMetric($product->stock_maximum) : '—' }}</td>
                            <td>{{ $product->reorder_level !== null ? $product->formatStockMetric($product->reorder_level) : '—' }}</td>
                            <td>
                                @if($product->is_stockable === false)
                                    <span class="status-pill status-neutral">NON STOCKABLE</span>
                                @elseif($product->currentStockQuantity() === null)
                                    <span class="status-pill status-neutral">NON INITIALISÉ</span>
                                @else
                                    @php($state = $product->stockState())
                                    @switch($state)
                                        @case('NORMAL')<span class="status-pill status-green">NORMAL</span>@break
                                        @case('RÉAPPROVISIONNEMENT')<span class="status-pill status-warning">RÉAPPROVISIONNEMENT</span>@break
                                        @case('RUPTURE')<span class="status-pill status-red">RUPTURE</span>@break
                                        @case('À SURVEILLER')<span class="status-pill status-warning">À SURVEILLER</span>@break
                                        @default<span class="status-pill status-neutral">{{ $state }}</span>@endswitch
                                @endif
                            </td>
                            <td><a class="text-link" href="{{ route('products.show', $product) }}">Voir</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <span>▣</span>
                                    <p>Aucun produit disponible.</p>
                                    <small>Le stock sera affiché ici dès qu'un produit est associé à l'entreprise.</small>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
