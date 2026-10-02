@extends('layouts.app', ['heading' => 'Entrée de stock', 'eyebrow' => 'Stock'])

@section('content')
    <div class="mb-8">
        <p class="section-kicker">Stock</p>
        <h2 class="hero-title">Entrée de stock</h2>
        <p class="muted-copy">Enregistrer une arrivée de stock dans l’entrepôt sélectionné.</p>
    </div>

    <section class="panel max-w-3xl">
        <form method="POST" action="{{ route('stock.movements.entry.store') }}" class="grid gap-5">
            @csrf
            <label class="field">
                <span>Produit</span>
                <select name="product_id" required>
                    <option value="">Sélectionner un produit</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }} ({{ $product->code }})</option>
                    @endforeach
                </select>
            </label>

            <label class="field">
                <span>Entrepôt</span>
                <select name="warehouse_id" required>
                    <option value="">Sélectionner un entrepôt</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>

            <div class="grid gap-5 md:grid-cols-2">
                <label class="field">
                    <span>Quantité</span>
                    <input type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity') }}" required />
                </label>
                <label class="field">
                    <span>Coût unitaire</span>
                    <input type="number" step="0.0001" min="0" name="unit_cost" value="{{ old('unit_cost') }}" required />
                </label>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <label class="field">
                    <span>Référence</span>
                    <input type="text" name="reference" value="{{ old('reference') }}" placeholder="Réception, bon de livraison..." />
                </label>
                <label class="field">
                    <span>Date</span>
                    <input type="date" name="occurred_at" value="{{ old('occurred_at', now()->toDateString()) }}" />
                </label>
            </div>

            @if ($errors->any())
                <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-3">
                <button type="submit" class="button-primary">Enregistrer l’entrée</button>
                <a href="{{ route('stock.movements.index') }}" class="button-secondary">Annuler</a>
            </div>
        </form>
    </section>
@endsection
