@extends('layouts.app', ['heading' => 'Créer un inventaire', 'eyebrow' => 'Stock'])
@section('content')
    <div class="mb-8">
        <p class="section-kicker">Stock</p>
        <h2 class="hero-title">Nouvel inventaire physique</h2>
        <p class="muted-copy">Le stock théorique de tous les produits stockables sera capturé pour l’entrepôt choisi.</p>
    </div>

    <section class="panel max-w-3xl">
        <form method="POST" action="{{ route('stock.inventories.store') }}" class="grid gap-5">
            @csrf
            <div>
                <label for="warehouse_id" class="mb-2 block font-semibold">Entrepôt</label>
                <select id="warehouse_id" name="warehouse_id" required class="form-input w-full">
                    <option value="">Sélectionner un entrepôt</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(old('warehouse_id') === $warehouse->id)>{{ $warehouse->name }}{{ $warehouse->is_default ? ' — principal' : '' }}</option>
                    @endforeach
                </select>
                @error('warehouse_id')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="notes" class="mb-2 block font-semibold">Notes</label>
                <textarea id="notes" name="notes" rows="4" class="form-input w-full">{{ old('notes') }}</textarea>
                @error('notes')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            @error('products')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="button-primary" @disabled($warehouses->isEmpty())>Capturer le stock théorique</button>
                <a href="{{ route('stock.inventories.index') }}" class="button-secondary">Annuler</a>
            </div>
            @if($warehouses->isEmpty())
                <p class="muted-copy">Aucun entrepôt actif n’est disponible dans votre entreprise.</p>
            @endif
        </form>
    </section>
@endsection
