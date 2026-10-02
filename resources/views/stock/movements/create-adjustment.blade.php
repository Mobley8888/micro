@extends('layouts.app', ['heading' => 'Ajustement de stock', 'eyebrow' => 'Stock'])

@section('content')
    <div class="mb-8">
        <p class="section-kicker">Stock</p>
        <h2 class="hero-title">Ajustement de stock</h2>
        <p class="muted-copy">Corriger le stock avec un delta positif ou négatif.</p>
    </div>

    <section class="panel max-w-3xl">
        <form method="POST" action="{{ route('stock.movements.adjustment.store') }}" class="grid gap-5">
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
                    <span>Delta</span>
                    <input type="number" step="0.001" name="delta" value="{{ old('delta') }}" required />
                </label>
                <label class="field">
                    <span>Date</span>
                    <input type="date" name="occurred_at" value="{{ old('occurred_at', now()->toDateString()) }}" />
                </label>
            </div>

            <label class="field">
                <span>Référence</span>
                <input type="text" name="reference" value="{{ old('reference') }}" placeholder="Ajustement, comptage, correction..." />
            </label>

            <label class="field">
                <span>Motif</span>
                <textarea name="reason" rows="3" required>{{ old('reason') }}</textarea>
            </label>

            @if ($errors->any())
                <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-3">
                <button type="submit" class="button-primary">Enregistrer l’ajustement</button>
                <a href="{{ route('stock.movements.index') }}" class="button-secondary">Annuler</a>
            </div>
        </form>
    </section>
@endsection
