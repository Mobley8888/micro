@extends('layouts.app', ['heading' => 'Commandes fournisseurs', 'eyebrow' => 'Achats'])
@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div><p class="section-kicker">Achats</p><h2 class="hero-title">Commandes fournisseurs</h2><p class="muted-copy">Le stock est mis à jour uniquement lorsqu’une réception est validée.</p></div>
        @if(auth()->user()->hasPermission('purchases.create'))<a href="{{ route('purchases.create') }}" class="button-primary">Nouvelle commande</a>@endif
    </div>

    <section class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Référence</th><th>Fournisseur</th><th>Date</th><th>Statut</th><th>Réceptions</th><th>Total</th><th></th></tr></thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td class="font-semibold">{{ $order->number }}</td><td>{{ $order->supplier->name }}</td>
                            <td>{{ $order->ordered_at->format('d/m/Y') }}</td><td><span class="status-pill status-neutral">{{ str_replace('_', ' ', ucfirst($order->status)) }}</span></td>
                            <td>{{ $order->receipts_count }}</td><td>{{ number_format((float) $order->total, 2, ',', ' ') }} {{ $order->currency }}</td>
                            <td><a class="text-link" href="{{ route('purchases.show', $order) }}">Consulter</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-state"><span>▤</span><p>Aucune commande fournisseur.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $orders->links() }}</div>
    </section>
@endsection
