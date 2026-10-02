@extends('layouts.app', ['heading' => 'Créances', 'eyebrow' => 'Suivi des règlements'])
@section('content')
<div class="mb-8"><p class="section-kicker">Recouvrement</p><h2 class="hero-title">Les échéances à suivre.</h2><p class="muted-copy">Factures, règlements reçus et soldes restant dus pour votre entreprise.</p></div>
<section class="panel">
    <form method="get" class="mb-6 grid gap-3 md:grid-cols-4">
        <input class="search-input" name="search" value="{{ request('search') }}" placeholder="Facture ou client">
        <select class="search-input" name="customer_id"><option value="">Tous les clients</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(request('customer_id') === $customer->id)>{{ $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name) }}</option>@endforeach</select>
        <select class="search-input" name="status"><option value="">Tous les statuts</option>@foreach(['unpaid' => 'Impayée', 'partially_paid' => 'Partiellement payée', 'paid' => 'Payée', 'overdue' => 'Échue'] as $status => $label)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $label }}</option>@endforeach</select>
        <input class="search-input" type="date" name="period_from" value="{{ request('period_from') }}" aria-label="Factures depuis">
        <input class="search-input" type="date" name="period_to" value="{{ request('period_to') }}" aria-label="Factures jusqu’au">
        <input class="search-input" type="date" name="due_from" value="{{ request('due_from') }}" aria-label="Échéances depuis">
        <input class="search-input" type="date" name="due_to" value="{{ request('due_to') }}" aria-label="Échéances jusqu’au">
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="overdue_only" value="1" @checked(request()->boolean('overdue_only'))> Échues uniquement</label>
        <button class="button-primary">Filtrer</button>
    </form>
    @if($receivables->isEmpty())<div class="empty-state"><p>Aucune créance trouvée</p><small>Les soldes de factures apparaîtront ici.</small></div>
    @else<div class="table-wrap"><table class="data-table"><thead><tr><th>Facture</th><th>Client</th><th>Date</th><th>Échéance</th><th>Montant</th><th>Payé</th><th>Restant</th><th>Statut</th><th>Retard</th></tr></thead><tbody>@foreach($receivables as $receivable)@php($invoice = $receivable->invoice)@php($lateDays = $invoice->daysOverdue())<tr><td><a class="font-semibold text-[#28745d]" href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td><td>{{ $receivable->customer->legal_name ?: trim($receivable->customer->first_name.' '.$receivable->customer->last_name) }}</td><td>{{ $invoice->issue_date->format('d/m/Y') }}</td><td>{{ $receivable->due_date?->format('d/m/Y') ?? '—' }}</td><td>{{ number_format($receivable->amount_due, 2, ',', ' ') }} FCFA</td><td>{{ number_format($receivable->amount_paid, 2, ',', ' ') }} FCFA</td><td>{{ number_format($receivable->balance_due, 2, ',', ' ') }} FCFA</td><td>{{ $lateDays > 0 ? 'Échue' : match ($receivable->status) {'paid' => 'Payée', 'partially_paid' => 'Partielle', default => 'Impayée'} }}</td><td>{{ $lateDays > 0 ? $lateDays.' jour(s)' : '—' }}</td></tr>@endforeach</tbody></table></div><div class="mt-5">{{ $receivables->links() }}</div>@endif
</section>
@endsection
