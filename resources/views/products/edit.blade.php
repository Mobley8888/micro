@extends('layouts.app', ['heading' => 'Modifier le produit ou service', 'eyebrow' => 'Catalogue'])
@section('content')
<div class="mb-8">
    <p class="section-kicker">{{ $product->code }}</p>
    <h2 class="hero-title">Modifier {{ $product->name }}.</h2>
    <p class="muted-copy">Les lignes commerciales existantes conservent leur référence produit.</p>
</div>
@if ($errors->any())<div class="flash-error">{{ $errors->first() }}</div>@endif
<form class="panel form-grid" method="post" action="{{ route('products.update', $product) }}">@csrf @method('PUT')<div class="form-fields"><label>Type<select name="type" required>
                <option value="service" @selected($product->type === 'service')>Service</option>
                <option value="product" @selected($product->type === 'product')>Produit</option>
            </select></label><label>Code<input name="code" value="{{ old('code', $product->code) }}" required></label><label>Nom<input name="name" value="{{ old('name', $product->name) }}" required></label><label>Prix de vente HT<input type="number" min="0" step="0.01" name="sale_price" value="{{ old('sale_price', $product->sale_price) }}" required></label><label>TVA<select name="tax_id">
                <option value="">Exonéré</option>@foreach($taxes as $tax)<option value="{{ $tax->id }}" @selected(old('tax_id', $product->tax_id) == $tax->id)>{{ $tax->name }} ({{ $tax->rate }} %)</option>@endforeach
            </select></label><label class="flex items-center gap-2"><input type="hidden" name="is_stockable" value="0"><input class="!w-auto" type="checkbox" name="is_stockable" value="1" @checked((bool) old('is_stockable', $product->is_stockable))> Produit stockable</label><label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input class="!w-auto" type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $product->is_active))> Actif</label></div>
    <div class="form-fields">
        <div class="md:col-span-2 mb-2">
            <p class="section-kicker">Gestion du stock</p>
            <p class="muted-copy">Le suivi du stock est configuré au niveau du produit et de l'entreprise.</p>
        </div>
        <label>Prix d'achat HT<input type="number" min="0" step="0.01" name="purchase_price" value="{{ old('purchase_price', $product->purchase_price) }}" placeholder="0"></label>
        <label>Stock minimum<input type="number" min="0" step="0.001" name="stock_minimum" value="{{ old('stock_minimum', $product->stock_minimum) }}" placeholder="0"></label>
        <label>Stock maximum<input type="number" min="0" step="0.001" name="stock_maximum" value="{{ old('stock_maximum', $product->stock_maximum) }}" placeholder="0"></label>
        <label>Seuil de réapprovisionnement<input type="number" min="0" step="0.001" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level) }}" placeholder="0"></label>
        <div class="md:col-span-2 rounded border border-[#edf1ef] bg-[#f8fbfa] p-3">
            <p class="font-semibold text-[#173b36]">Méthode de valorisation</p>
            <p class="muted-copy mb-0">{{ auth()->user()?->company?->valuation_method ?? 'Non configuré' }}</p>
        </div>
        <div class="md:col-span-2 rounded border border-[#edf1ef] bg-[#f8fbfa] p-3">
            <p class="font-semibold text-[#173b36]">Stock négatif autorisé</p>
            <p class="muted-copy mb-0">{{ auth()->user()?->company?->allow_negative_stock ? 'Oui' : 'Non' }}</p>
        </div>
    </div>
    <label class="form-fields">Description<textarea class="search-input" name="description" rows="5">{{ old('description', $product->description) }}</textarea></label>
    <div class="form-actions"><a class="button-secondary" href="{{ route('products.show', $product) }}">Annuler</a><button class="button-primary" type="submit">Enregistrer</button></div>
</form>
@endsection