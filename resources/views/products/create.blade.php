@extends('layouts.app', ['heading' => 'Nouveau produit ou service', 'eyebrow' => 'Catalogue'])
@section('content')
<div class="mb-8">
    <p class="section-kicker">Catalogue</p>
    <h2 class="hero-title">Créer une offre.</h2>
    <p class="muted-copy">Les prix sont enregistrés en HT ; la taxe reste configurable.</p>
</div>
@if ($errors->any())<div class="flash-error">{{ $errors->first() }}</div>@endif
<form class="panel form-grid" method="post" action="{{ route('products.store') }}">@csrf<div class="form-fields"><label>Type<select name="type" required>
                <option value="service">Service</option>
                <option value="product">Produit</option>
            </select></label><label>Code<input name="code" value="{{ old('code') }}" required placeholder="FORM-001"></label><label>Nom<input name="name" value="{{ old('name') }}" required></label><label>Prix de vente HT<input type="number" min="0" step="0.01" name="sale_price" value="{{ old('sale_price', 0) }}" required></label><label>TVA<select name="tax_id">
                <option value="">Exonéré</option>@foreach($taxes as $tax)<option value="{{ $tax->id }}" @selected(old('tax_id')==$tax->id)>{{ $tax->name }} ({{ $tax->rate }} %)</option>@endforeach
            </select></label><label class="flex items-center gap-2"><input type="hidden" name="is_stockable" value="0"><input class="!w-auto" type="checkbox" name="is_stockable" value="1" @checked(old('is_stockable', false))> Produit stockable</label><label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input class="!w-auto" type="checkbox" name="is_active" value="1" checked> Actif</label></div>
    <div class="form-fields">
        <div class="md:col-span-2 mb-2">
            <p class="section-kicker">Gestion du stock</p>
            <p class="muted-copy">Activez le suivi pour ce produit uniquement si la gestion de stock est nécessaire.</p>
        </div>
        <label>Prix d'achat HT<input type="number" min="0" step="0.01" name="purchase_price" value="{{ old('purchase_price', 0) }}" placeholder="0"></label>
        <label>Stock minimum<input type="number" min="0" step="0.001" name="stock_minimum" value="{{ old('stock_minimum', 0) }}" placeholder="0"></label>
        <label>Stock maximum<input type="number" min="0" step="0.001" name="stock_maximum" value="{{ old('stock_maximum', 0) }}" placeholder="0"></label>
        <label>Seuil de réapprovisionnement<input type="number" min="0" step="0.001" name="reorder_level" value="{{ old('reorder_level', 0) }}" placeholder="0"></label>
        <div class="md:col-span-2 rounded border border-[#edf1ef] bg-[#f8fbfa] p-3">
            <p class="font-semibold text-[#173b36]">Méthode de valorisation</p>
            <p class="muted-copy mb-0">{{ auth()->user()?->company?->valuation_method ?? 'Non configuré' }}</p>
        </div>
        <div class="md:col-span-2 rounded border border-[#edf1ef] bg-[#f8fbfa] p-3">
            <p class="font-semibold text-[#173b36]">Stock négatif autorisé</p>
            <p class="muted-copy mb-0">{{ auth()->user()?->company?->allow_negative_stock ? 'Oui' : 'Non' }}</p>
        </div>
    </div>
    <label class="form-fields">Description<textarea class="search-input" name="description" rows="5">{{ old('description') }}</textarea></label>
    <div class="form-actions"><a class="button-secondary" href="{{ route('products.index') }}">Annuler</a><button class="button-primary" type="submit">Enregistrer</button></div>
</form>
@endsection