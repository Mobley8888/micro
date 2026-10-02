@extends('layouts.app', ['heading' => 'Nouvelle commande fournisseur', 'eyebrow' => 'Achats'])
@section('content')
    <div class="mb-8"><p class="section-kicker">Achats</p><h2 class="hero-title">Nouvelle commande fournisseur</h2><p class="muted-copy">La réception en stock sera enregistrée uniquement après confirmation et réception des quantités.</p></div>

    <section class="panel">
        <form method="POST" action="{{ route('purchases.store') }}" class="grid gap-6">
            @csrf
            <div class="grid gap-4 md:grid-cols-3">
                <div><label for="supplier_id" class="mb-2 block font-semibold">Fournisseur</label><select id="supplier_id" name="supplier_id" required class="form-input w-full"><option value="">Sélectionner</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id') === $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>@error('supplier_id')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="ordered_at" class="mb-2 block font-semibold">Date de commande</label><input id="ordered_at" type="date" name="ordered_at" required value="{{ old('ordered_at', today()->toDateString()) }}" class="form-input w-full"></div>
                <div><label for="expected_at" class="mb-2 block font-semibold">Date prévue</label><input id="expected_at" type="date" name="expected_at" value="{{ old('expected_at') }}" class="form-input w-full"></div>
            </div>
            <div>
                <h3 class="mb-4 text-xl font-semibold">Articles stockables</h3>
                @error('items')<p class="mb-3 text-sm text-red-700">{{ $message }}</p>@enderror
                <div class="grid gap-3">
                    @foreach($products as $index => $product)
                        <div data-purchase-line class="grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-[2fr_1fr_1fr_1fr] md:items-center">
                            <label class="flex items-center gap-3 font-semibold"><input type="checkbox" data-purchase-toggle>{{ $product->name }} <span class="muted-copy">{{ $product->code }}</span></label>
                            <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $product->id }}" disabled data-purchase-field>
                            <div><label class="mb-1 block text-sm" for="quantity-{{ $index }}">Quantité</label><input id="quantity-{{ $index }}" type="number" min="0.001" max="100000000" step="0.001" name="items[{{ $index }}][quantity]" value="{{ old('items.'.$index.'.quantity', '1') }}" disabled data-purchase-field class="form-input w-full"></div>
                            <div><label class="mb-1 block text-sm" for="unit-cost-{{ $index }}">Coût unitaire</label><input id="unit-cost-{{ $index }}" type="number" min="0" max="1000000000" step="0.0001" name="items[{{ $index }}][unit_cost]" value="{{ old('items.'.$index.'.unit_cost', $product->purchase_price) }}" disabled data-purchase-field class="form-input w-full"></div>
                            <div><label class="mb-1 block text-sm" for="unit-{{ $index }}">Unité</label><input id="unit-{{ $index }}" type="text" maxlength="50" name="items[{{ $index }}][unit]" value="{{ old('items.'.$index.'.unit', 'unité') }}" disabled data-purchase-field class="form-input w-full"></div>
                        </div>
                    @endforeach
                </div>
                @foreach($errors->getMessages() as $key => $messages)
                    @if(str_starts_with($key, 'items.'))
                        <p class="mt-2 text-sm text-red-700">{{ $messages[0] }}</p>
                    @endif
                @endforeach
            </div>
            <div><label for="notes" class="mb-2 block font-semibold">Notes</label><textarea id="notes" name="notes" rows="3" class="form-input w-full">{{ old('notes') }}</textarea></div>
            <div class="flex flex-wrap gap-3"><button type="submit" class="button-primary">Créer la commande</button><a href="{{ route('purchases.index') }}" class="button-secondary">Annuler</a></div>
        </form>
    </section>
    <script>
        document.querySelectorAll('[data-purchase-line]').forEach((line) => {
            const toggle = line.querySelector('[data-purchase-toggle]');
            toggle.addEventListener('change', () => {
                line.querySelectorAll('[data-purchase-field]').forEach((field) => {
                    field.disabled = ! toggle.checked;
                });
            });
        });
    </script>
@endsection
