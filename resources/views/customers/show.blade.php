@extends('layouts.app', ['heading' => 'Fiche client', 'eyebrow' => 'Relation client'])
@section('content')<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">{{ $customer->code }}</p>
        <h2 class="hero-title">{{ $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name) }}</h2>
        <p class="muted-copy">{{ $customer->email ?: 'Aucune adresse email' }} · {{ $customer->phone ?: 'Aucun téléphone' }}</p>
    </div>
    @if(auth()->user()->hasPermission('customers.manage'))
        <div class="flex gap-2">
            <form method="post" action="{{ route('customers.destroy', $customer) }}">@csrf @method('DELETE')<button class="button-secondary" type="submit">Archiver</button></form><a class="button-secondary" href="{{ route('customers.edit', $customer) }}">Modifier la fiche</a>
        </div>
    @endif
</div>
<section class="panel mb-8">
    <div class="panel-heading"><div><p class="section-kicker">Situation financière</p><h3>Facturation et recouvrement</h3></div></div>
    <div class="stats-grid">
        <div class="stat-card accent-green"><p>Total facturé</p><strong>{{ number_format($financial['invoiced'], 0, ',', ' ') }} <em>FCFA</em></strong></div>
        <div class="stat-card accent-blue"><p>Total encaissé</p><strong>{{ number_format($financial['collected'], 0, ',', ' ') }} <em>FCFA</em></strong></div>
        <div class="stat-card accent-red"><p>Total restant dû</p><strong>{{ number_format($financial['balance'], 0, ',', ' ') }} <em>FCFA</em></strong></div>
        <div class="stat-card accent-orange"><p>Total en retard</p><strong>{{ number_format($financial['overdue'], 0, ',', ' ') }} <em>FCFA</em></strong></div>
    </div>
    <div class="table-wrap mt-6"><table class="data-table"><thead><tr><th>Facture</th><th>Date</th><th>Échéance</th><th>Total</th><th>Payé</th><th>Solde</th><th>Statut</th></tr></thead><tbody>
        @forelse($invoices as $invoice)<tr><td><a class="font-semibold text-[#28745d]" href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td><td>{{ $invoice->issue_date->format('d/m/Y') }}</td><td>{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</td><td>{{ number_format($invoice->total, 2, ',', ' ') }} FCFA</td><td>{{ number_format($invoice->amount_paid, 2, ',', ' ') }} FCFA</td><td>{{ number_format($invoice->balance_due, 2, ',', ' ') }} FCFA</td><td>{{ $invoice->daysOverdue() > 0 ? 'Échue · '.$invoice->daysOverdue().' j' : $invoice->financialStatusLabel() }}</td></tr>@empty<tr><td colspan="7">Aucune facture pour ce client.</td></tr>@endforelse
    </tbody></table></div>
</section>
<section class="panel mt-8">
    <div class="panel-heading">
        <div>
            <p class="section-kicker">Historique</p>
            <h3>Activité du client</h3>
        </div>
    </div>@forelse($customer->interactions as $interaction)<div class="border-t border-[#edf1ef] py-3"><strong>{{ $interaction->subject }}</strong>
        <p class="muted-copy">{{ $interaction->description }}</p>
    </div>@empty<div class="empty-state"><span>◌</span>
        <p>Aucune activité</p><small>Les devis, factures, paiements et interactions seront regroupés ici.</small>
    </div>@endforelse
</section>@endsection