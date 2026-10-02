@extends('layouts.app', ['heading' => 'Détail produit ou service', 'eyebrow' => 'Catalogue'])
@section('content')
<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">{{ $product->code }}</p>
        <h2 class="hero-title">{{ $product->name }}</h2>
        <p class="muted-copy">{{ $product->type === 'product' ? 'Produit' : 'Service' }} · créé le {{ $product->created_at?->format('d/m/Y') }}</p>
    </div>
    @if($canManageProducts)
        <div class="flex gap-2">
            <a class="button-secondary" href="{{ route('products.edit', $product) }}">Modifier</a>
            <form method="post" action="{{ route($product->is_active ? 'products.deactivate' : 'products.activate', $product) }}">
                @csrf
                @method('PATCH')
                <button class="button-secondary">{{ $product->is_active ? 'Désactiver' : 'Activer' }}</button>
            </form>
        </div>
    @endif
</div>
@if($canManageProducts)
    <section class="panel">
        <div class="stats-grid">
            <div class="stat-card accent-green">
                <p>Prix de vente HT</p><strong>{{ number_format($product->sale_price, 0, ',', ' ') }} <em>FCFA</em></strong>
            </div>
            <div class="stat-card accent-blue">
                <p>TVA</p><strong>{{ $product->tax ? $product->tax->rate.' %' : '0 %' }}</strong>
            </div>
            <div class="stat-card {{ $product->is_active ? 'accent-green' : 'accent-red' }}">
                <p>Statut</p><strong>{{ $product->is_active ? 'Actif' : 'Inactif' }}</strong>
            </div>
        </div>
        <div class="mt-8 border-t border-[#edf1ef] pt-6">
            <p class="section-kicker">Description</p>
            <p class="muted-copy">{{ $product->description ?: 'Aucune description renseignée.' }}</p>
        </div>
    </section>
@endif

@if($canManageProducts || $canViewStock)
<section class="mt-8 panel">
    <div class="panel-heading">
        <div>
            <p class="section-kicker">Gestion du stock</p>
            <h3>Suivi du stock</h3>
        </div>
    </div>
    <div class="stats-grid">
        <div class="stat-card">
            <p>Produit stockable</p>
            <strong>{{ $product->is_stockable ? 'Oui' : 'Non' }}</strong>
        </div>
        <div class="stat-card accent-blue">
            <p>Stock actuel</p>
            <strong>
                @if($product->is_stockable === false)
                    Non stockable
                @elseif($product->currentStockQuantity() === null)
                    Stock non initialisé
                @else
                    {{ $product->stockDisplayLabel() }}
                @endif
            </strong>
        </div>
        <div class="stat-card accent-green">
            <p>Stock minimum</p>
            <strong>{{ $product->stock_minimum !== null ? $product->formatStockMetric($product->stock_minimum) : '—' }}</strong>
        </div>
        <div class="stat-card accent-orange">
            <p>Stock maximum</p>
            <strong>{{ $product->stock_maximum !== null ? $product->formatStockMetric($product->stock_maximum) : '—' }}</strong>
        </div>
        <div class="stat-card accent-red">
            <p>Seuil de réapprovisionnement</p>
            <strong>{{ $product->reorder_level !== null ? $product->formatStockMetric($product->reorder_level) : '—' }}</strong>
        </div>
        @if($canViewStockValuation)
            <div class="stat-card">
                <p>Prix d'achat</p>
                <strong>{{ $product->purchase_price !== null ? number_format((float) $product->purchase_price, 0, ',', ' ') . ' FCFA' : '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Méthode de valorisation</p>
                <strong>{{ $product->company?->valuation_method ?? '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Stock négatif autorisé</p>
                <strong>{{ $product->company && $product->company->allow_negative_stock ? 'Oui' : 'Non' }}</strong>
            </div>
        @endif
        <div class="stat-card">
            <p>État du stock</p>
            <strong>
                @if($product->is_stockable === false)
                    NON STOCKABLE
                @elseif($product->currentStockQuantity() === null)
                    NON INITIALISÉ
                @else
                    {{ $product->stockState() }}
                @endif
            </strong>
        </div>
    </div>
</section>
@endif
@endsection