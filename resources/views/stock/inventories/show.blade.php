@extends('layouts.app', ['heading' => 'Détail de l’inventaire', 'eyebrow' => 'Stock'])
@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <p class="section-kicker">{{ $inventoryCount->number }}</p>
            <h2 class="hero-title">Inventaire physique</h2>
            <p class="muted-copy">{{ $inventoryCount->warehouse->name }} · créé par {{ $inventoryCount->createdBy->name }} le {{ $inventoryCount->started_at->format('d/m/Y H:i') }}</p>
        </div>
        <span class="status-pill {{ $inventoryCount->status === 'validated' ? 'status-green' : 'status-warning' }}">{{ $inventoryCount->status === 'validated' ? 'Validé' : 'Brouillon' }}</span>
    </div>

    <section class="panel mb-8">
        <div class="grid gap-4 sm:grid-cols-3">
            <p>Entrepôt<strong class="block">{{ $inventoryCount->warehouse->name }}</strong></p>
            <p>Compté par<strong class="block">{{ $inventoryCount->validatedBy?->name ?? 'En attente de validation' }}</strong></p>
            <p>Date de validation<strong class="block">{{ $inventoryCount->validated_at?->format('d/m/Y H:i') ?? '—' }}</strong></p>
        </div>
        @if($inventoryCount->notes)
            <div class="mt-5 border-t pt-4">
                <p class="section-kicker">Notes</p>
                <p class="whitespace-pre-line">{{ $inventoryCount->notes }}</p>
            </div>
        @endif
    </section>

    <section class="panel">
        @if($inventoryCount->status === 'draft')
            <form method="POST" action="{{ route('stock.inventories.counts', $inventoryCount) }}" class="grid gap-5">
                @csrf
                @method('PATCH')
        @endif
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Stock théorique</th>
                        <th>Quantité comptée</th>
                        <th>Écart</th>
                        <th>Valeur de l’écart</th>
                        @if($inventoryCount->status === 'draft')<th>Note</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($inventoryCount->items as $item)
                        <tr>
                            <td><div class="font-semibold">{{ $item->product->name }}</div><small>{{ $item->product->code }}</small></td>
                            <td>{{ $item->expected_quantity }}</td>
                            <td>
                                @if($inventoryCount->status === 'draft')
                                    <input type="number" name="counts[{{ $item->id }}]" min="0" max="100000000" step="0.001" value="{{ old('counts.'.$item->id, $item->counted_quantity) }}" class="form-input w-36" aria-label="Quantité comptée pour {{ $item->product->name }}">
                                @else
                                    {{ $item->counted_quantity }}
                                @endif
                            </td>
                            <td>{{ $item->difference_quantity ?? '—' }}</td>
                            <td>{{ $item->difference_value !== null ? number_format((float) $item->difference_value, 2, ',', ' ').' FCFA' : '—' }}</td>
                            @if($inventoryCount->status === 'draft')
                                <td><input type="text" name="item_notes[{{ $item->id }}]" value="{{ old('item_notes.'.$item->id, $item->notes) }}" maxlength="1000" class="form-input w-full" aria-label="Note pour {{ $item->product->name }}"></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($inventoryCount->status === 'draft')
                <div>
                    <label for="notes" class="mb-2 block font-semibold">Notes de l’inventaire</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="5000" class="form-input w-full">{{ old('notes', $inventoryCount->notes) }}</textarea>
                </div>
                @error('counts')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @error('inventory')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @error('item_notes.*')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @foreach($errors->getMessages() as $key => $messages)
                    @if(str_starts_with($key, 'counts.'))
                        <p class="text-sm text-red-700">{{ $messages[0] }}</p>
                    @endif
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="button-secondary">Enregistrer le comptage</button>
                    <a href="{{ route('stock.inventories.index') }}" class="button-secondary">Retour</a>
                </div>
            </form>
            @if(auth()->user()->hasPermission('stock.validate'))
                <form method="POST" action="{{ route('stock.inventories.validate', $inventoryCount) }}" class="mt-4" onsubmit="return confirm('Valider cet inventaire et appliquer les écarts au stock ?')">
                    @csrf
                    <button type="submit" class="button-primary">Valider et régulariser le stock</button>
                    @error('inventory')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
                </form>
            @endif
        @else
            <div class="mt-5">
                <a href="{{ route('stock.inventories.index') }}" class="button-secondary">Retour aux inventaires</a>
            </div>
        @endif
    </section>
@endsection
