@extends('layouts.app', ['heading' => 'Inventaires physiques', 'eyebrow' => 'Stock'])
@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <p class="section-kicker">Stock</p>
            <h2 class="hero-title">Inventaires physiques</h2>
            <p class="muted-copy">Comparez les quantités théoriques aux quantités réellement comptées.</p>
        </div>
        @if(auth()->user()->hasPermission('stock.inventory'))
            <a href="{{ route('stock.inventories.create') }}" class="button-primary">Créer un inventaire</a>
        @endif
    </div>

    <section class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Entrepôt</th>
                        <th>Statut</th>
                        <th>Produits</th>
                        <th>Créé par</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($inventories as $inventory)
                        <tr>
                            <td class="font-semibold">{{ $inventory->number }}</td>
                            <td>{{ $inventory->warehouse->name }}</td>
                            <td><span class="status-pill {{ $inventory->status === 'validated' ? 'status-green' : 'status-warning' }}">{{ $inventory->status === 'validated' ? 'Validé' : 'Brouillon' }}</span></td>
                            <td>{{ $inventory->items_count }}</td>
                            <td>{{ $inventory->createdBy->name }}</td>
                            <td>{{ $inventory->started_at->format('d/m/Y H:i') }}</td>
                            <td><a class="text-link" href="{{ route('stock.inventories.show', $inventory) }}">Consulter</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <span>▣</span>
                                    <p>Aucun inventaire enregistré.</p>
                                    <small>Créez un inventaire pour capturer le stock théorique d’un entrepôt.</small>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $inventories->links() }}</div>
    </section>
@endsection
