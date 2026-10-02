@extends('layouts.app', ['heading' => 'Historique du stock', 'eyebrow' => 'Stock'])

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
        <div>
            <p class="section-kicker">Stock</p>
            <h2 class="hero-title">Mouvements de stock</h2>
            <p class="muted-copy">Historique, recherche et traçabilité des opérations de stock.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if(auth()->user()->hasPermission('stock.create'))
                <a href="{{ route('stock.movements.entry.create') }}" class="button-primary">Entrée</a>
                <a href="{{ route('stock.movements.exit.create') }}" class="button-secondary">Sortie</a>
            @endif
            @if(auth()->user()->hasPermission('stock.adjust'))
                <a href="{{ route('stock.movements.adjustment.create') }}" class="button-secondary">Ajustement</a>
            @endif
        </div>
    </div>

    <section class="panel mb-8">
        <div class="panel-heading">
            <div>
                <p class="section-kicker">Filtres</p>
                <h3>Recherche</h3>
            </div>
        </div>
        <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-7">
            <label class="field">
                <span>Produit</span>
                <select name="product_id">
                    <option value="">Tous</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>{{ $product->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>Entrepôt</span>
                <select name="warehouse_id">
                    <option value="">Tous</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>Type</span>
                <select name="type">
                    <option value="">Tous</option>
                    <option value="manual_entry" @selected(($filters['type'] ?? '') === 'manual_entry')>Entrée</option>
                    <option value="manual_exit" @selected(($filters['type'] ?? '') === 'manual_exit')>Sortie</option>
                    <option value="adjustment" @selected(($filters['type'] ?? '') === 'adjustment')>Ajustement</option>
                </select>
            </label>
            <label class="field">
                <span>Sens</span>
                <select name="direction">
                    <option value="">Tous</option>
                    <option value="in" @selected(($filters['direction'] ?? '') === 'in')>Entrée</option>
                    <option value="out" @selected(($filters['direction'] ?? '') === 'out')>Sortie</option>
                </select>
            </label>
            <label class="field">
                <span>Recherche</span>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Référence / produit" />
            </label>
            <label class="field">
                <span>Du</span>
                <input type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" />
            </label>
            <label class="field">
                <span>Au</span>
                <input type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" />
            </label>
            <div class="flex items-end gap-2 md:col-span-2 xl:col-span-1">
                <button type="submit" class="button-secondary w-full">Filtrer</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Produit</th>
                    <th>Type</th>
                    <th>Direction</th>
                    <th>Quantité</th>
                    <th>Entrepôt</th>
                    <th>Référence</th>
                    <th>Utilisateur</th>
                    <th>Coût</th>
                    <th>Source</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($movements as $movement)
                    <tr>
                        <td>{{ $movement->occurred_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td>{{ $movement->product?->name ?? '—' }}</td>
                        <td>{{ match($movement->type) {
                            'manual_entry' => 'Entrée',
                            'manual_exit' => 'Sortie',
                            'adjustment' => 'Ajustement',
                            'opening_balance' => 'Solde d’ouverture',
                            'purchase_receipt' => 'Réception',
                            'sale' => 'Vente',
                            default => ucfirst(str_replace('_', ' ', $movement->type)),
                        } }}</td>
                        <td>
                            <span class="status-pill {{ $movement->direction === 'in' ? 'status-green' : 'status-red' }}">
                                {{ $movement->direction === 'in' ? 'Entrée' : 'Sortie' }}
                            </span>
                        </td>
                        <td>{{ $movement->quantity }}</td>
                        <td>{{ $movement->warehouse?->name ?? '—' }}</td>
                        <td>{{ $movement->reference ?: '—' }}</td>
                        <td>{{ $movement->createdBy?->name ?? '—' }}</td>
                        <td>{{ $movement->total_cost !== null ? number_format((float) $movement->total_cost, 0, ',', ' ') . ' FCFA' : '—' }}</td>
                        <td>{{ $movement->source_type ? class_basename($movement->source_type) : 'Manuel' }}</td>
                        <td><a href="{{ route('stock.movements.show', $movement) }}" class="text-link">Détail</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11">
                            <div class="empty-state">
                                <span>▣</span>
                                <p>Aucun mouvement de stock.</p>
                                <small>Les entrées, sorties et ajustements apparaîtront ici.</small>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($movements->hasPages())
            <div class="mt-5">
                {{ $movements->links() }}
            </div>
        @endif
    </section>
@endsection
