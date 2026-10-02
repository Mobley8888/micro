@extends('layouts.app', ['heading' => 'Détail de la commande', 'eyebrow' => 'Achats'])
@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div><p class="section-kicker">{{ $purchase->number }}</p><h2 class="hero-title">Commande fournisseur</h2><p class="muted-copy">{{ $purchase->supplier->name }} · {{ $purchase->ordered_at->format('d/m/Y') }}</p></div>
        <span class="status-pill status-neutral">{{ str_replace('_', ' ', ucfirst($purchase->status)) }}</span>
    </div>

    <section class="panel mb-8">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Produit</th><th>Commandé</th><th>Reçu</th><th>Coût unitaire</th><th>Total ligne</th></tr></thead>
                <tbody>
                    @foreach($purchase->items as $item)
                        <tr><td><strong>{{ $item->description }}</strong><small class="block">{{ $item->product_code }}</small></td><td>{{ $item->quantity }}</td><td>{{ $item->received_quantity }}</td><td>{{ number_format((float) $item->unit_cost, 4, ',', ' ') }} {{ $purchase->currency }}</td><td>{{ number_format((float) $item->line_total, 2, ',', ' ') }} {{ $purchase->currency }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
            @if($purchase->status === 'draft' && auth()->user()->hasPermission('purchases.confirm'))
                <form method="POST" action="{{ route('purchases.confirm', $purchase) }}">@csrf<button type="submit" class="button-primary">Confirmer la commande</button></form>
            @endif
            <a href="{{ route('purchases.index') }}" class="button-secondary">Retour aux commandes</a>
        </div>
    </section>

    @if(in_array($purchase->status, ['confirmed', 'partially_received'], true) && auth()->user()->hasPermission('purchases.receive'))
        <section class="panel mb-8">
            <h3 class="mb-2 text-xl font-semibold">Réceptionner les articles</h3>
            <p class="muted-copy mb-5">Les entrées de stock seront générées dans l’entrepôt principal de l’entreprise.</p>
            <form method="POST" action="{{ route('purchases.receive', $purchase) }}" class="grid gap-5">
                @csrf
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Produit</th><th>Reste à recevoir</th><th>Quantité reçue maintenant</th></tr></thead>
                        <tbody>
                            @foreach($purchase->items as $item)
                                @php($remaining = (float) $item->quantity - (float) $item->received_quantity)
                                <tr><td>{{ $item->description }}</td><td>{{ number_format($remaining, 3, ',', ' ') }}</td><td><input type="number" name="quantities[{{ $item->id }}]" value="{{ old('quantities.'.$item->id, '0') }}" min="0" max="{{ $remaining }}" step="0.001" class="form-input w-40" aria-label="Quantité reçue pour {{ $item->description }}"></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @foreach($errors->getMessages() as $key => $messages)
                    @if(str_starts_with($key, 'quantities'))
                        <p class="text-sm text-red-700">{{ $messages[0] }}</p>
                    @endif
                @endforeach
                <button type="submit" class="button-primary">Valider la réception et entrer en stock</button>
            </form>
        </section>
    @endif

    @if($purchase->receipts->isNotEmpty())
        <section class="panel">
            <h3 class="mb-5 text-xl font-semibold">Historique des réceptions</h3>
            <div class="grid gap-4">
                @foreach($purchase->receipts as $receipt)
                    <article class="rounded-xl border border-slate-200 p-4">
                        <div class="flex flex-wrap justify-between gap-3"><strong>{{ $receipt->number }}</strong><span>{{ $receipt->received_at->format('d/m/Y H:i') }} · {{ $receipt->receivedBy->name }}</span></div>
                        <ul class="mt-3 list-inside list-disc">
                            @foreach($receipt->items as $receiptItem)
                                <li>{{ $receiptItem->product->name }} — {{ $receiptItem->quantity }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
@endsection
