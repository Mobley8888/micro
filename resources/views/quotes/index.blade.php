@extends('layouts.app', ['heading' => 'Devis', 'eyebrow' => 'Relation client'])
@section('content')
<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">Ventes</p>
        <h2 class="hero-title">Des propositions qui avancent.</h2>
        <p class="muted-copy">Créez et suivez vos devis depuis un espace unique.</p>
    </div><a class="button-primary" href="{{ route('quotes.create') }}">+ Nouveau devis</a>
</div>
<section class="panel">
    <form class="toolbar" method="get"><input class="search-input" name="search" value="{{ request('search') }}" placeholder="Rechercher un numéro ou un client…"><select class="search-input" name="status">
            <option value="">Tous les statuts</option>@foreach(['draft' => 'Brouillon', 'sent' => 'Envoyé', 'accepted' => 'Accepté', 'rejected' => 'Refusé', 'expired' => 'Expiré', 'cancelled' => 'Annulé'] as $value => $label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach
        </select><button class="button-secondary">Filtrer</button></form>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Numéro</th>
                    <th>Client</th>
                    <th>Date</th>
                    <th>Échéance</th>
                    <th>Total HT</th>
                    <th>TVA</th>
                    <th>Total TTC</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>@forelse($quotes as $quote)<tr>
                    <td><a class="text-link" href="{{ route('quotes.show', $quote) }}">{{ $quote->number }}</a></td>
                    <td>{{ $quote->customer->legal_name ?: trim($quote->customer->first_name.' '.$quote->customer->last_name) }}</td>
                    <td>{{ $quote->issue_date->format('d/m/Y') }}</td>
                    <td>{{ $quote->due_date?->format('d/m/Y') ?: '—' }}</td>
                    <td>{{ number_format($quote->subtotal, 0, ',', ' ') }} FCFA</td>
                    <td>{{ number_format($quote->tax_amount, 0, ',', ' ') }} FCFA</td>
                    <td>{{ number_format($quote->total, 0, ',', ' ') }} FCFA</td>
                    <td><span class="status-pill status-neutral">{{ ucfirst($quote->status) }}</span></td>
                    <td><a class="text-link" href="{{ route('quotes.show', $quote) }}">Voir</a></td>
                </tr>@empty<tr>
                    <td colspan="9">
                        <div class="empty-state"><span>◇</span>
                            <p>Aucun devis enregistré</p><small>Créez votre première proposition commerciale.</small>
                        </div>
                    </td>
                </tr>@endforelse</tbody>
        </table>
    </div>{{ $quotes->links() }}
</section>
@endsection