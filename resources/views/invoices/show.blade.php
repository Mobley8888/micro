@extends('layouts.app', ['heading' => 'Détail de la facture', 'eyebrow' => 'Opérations financières'])
@section('content')
<style>
    @media print {
        @page { size: A4; margin: 16mm; }
        body { background: #fff !important; color: #111827 !important; }
        .sidebar-shell, .topbar, .no-print, .flash-success { display: none !important; }
        main, main > div { display: block !important; max-width: none !important; padding: 0 !important; margin: 0 !important; }
        .print-document { border: 0 !important; box-shadow: none !important; padding: 0 !important; }
        .table-wrap { overflow: visible !important; }
        table { width: 100% !important; border-collapse: collapse !important; }
        tr { break-inside: avoid; }
    }
</style>
<div class="mb-8 flex items-end justify-between no-print">
    <div><p class="section-kicker">{{ $invoice->number }}</p><h2 class="hero-title">Facture {{ $invoice->number }}.</h2><p class="muted-copy">{{ $invoice->customer->legal_name ?: trim($invoice->customer->first_name.' '.$invoice->customer->last_name) }}</p></div>
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" class="button-secondary" onclick="window.print()">Imprimer</button>
        <span class="status-pill status-neutral">{{ match($invoice->status) {'draft' => 'Brouillon', 'sent' => 'Émise', 'cancelled' => 'Annulée', default => ucfirst($invoice->status)} }} · {{ $invoice->financialStatusLabel() }}</span>
        @if(auth()->user()->hasPermission('invoice.create') && $invoice->status === 'draft')
            <form method="POST" action="{{ route('invoices.issue', $invoice) }}">
                @csrf
                <button type="submit" class="button-primary">Valider la facture</button>
            </form>
        @endif
        @if(auth()->user()->hasPermission('invoice.create') && in_array($invoice->status, ['draft', 'sent'], true) && $invoice->payments->isEmpty())
            <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" onsubmit="return confirm('Annuler cette facture ? Le stock déjà sorti sera réintégré.')">
                @csrf
                <button type="submit" class="button-secondary">Annuler la facture</button>
            </form>
        @endif
    </div>
</div>
<section class="panel print-document">
    <div class="panel-heading">
        <div class="flex items-center gap-4">
            @if($invoice->company->logo_path)
                <img src="{{ Storage::url($invoice->company->logo_path) }}" alt="Logo {{ $invoice->company->name }}" class="max-h-16 max-w-32 object-contain">
            @endif
            <div>
                <p class="section-kicker">{{ $invoice->company->legal_name ?: $invoice->company->name }}</p>
                <p>{{ $invoice->company->address ?? '' }} {{ $invoice->company->city }}</p>
                <p>{{ $invoice->company->email }} · {{ $invoice->company->phone }}</p>
                @if($invoice->company->tax_number)<p>N° fiscal : {{ $invoice->company->tax_number }}</p>@endif
                @if($invoice->company->registration_number)<p>N° d’enregistrement : {{ $invoice->company->registration_number }}</p>@endif
            </div>
        </div>
        <div class="text-right">
            <p class="section-kicker">FACTURE</p>
            <h2 class="text-2xl font-bold">{{ $invoice->number }}</h2>
            <p>Date : {{ $invoice->issue_date->format('d/m/Y') }}</p>
            <p>Échéance : {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</p>
            <p>{{ match($invoice->status) {'draft' => 'Brouillon', 'sent' => 'Émise', 'cancelled' => 'Annulée', default => ucfirst($invoice->status)} }} · {{ $invoice->financialStatusLabel() }}</p>
            @if($invoice->daysOverdue() > 0)<p class="font-bold text-red-700">ÉCHUE — {{ $invoice->daysOverdue() }} jour(s) de retard</p>@endif
            @if($invoice->quote && auth()->user()->hasPermission('quotes.manage'))<a class="text-[#28745d] underline no-print" href="{{ route('quotes.show', $invoice->quote) }}">Devis {{ $invoice->quote->number }}</a>@endif
        </div>
    </div>
    <div class="my-8 rounded-xl bg-[#f5f7f8] p-5">
        <p class="section-kicker">Client</p>
        <strong>{{ $invoice->customer->legal_name ?: trim($invoice->customer->first_name.' '.$invoice->customer->last_name) }}</strong>
        <p>{{ $invoice->customer->address }}</p>
        <p>{{ $invoice->customer->city }} {{ $invoice->customer->country }}</p>
        <p>{{ $invoice->customer->email }} · {{ $invoice->customer->phone }}</p>
    </div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Désignation</th><th>Qté</th><th>Prix unitaire</th><th>Remise</th><th>TVA</th><th>Montant TVA</th><th>Total</th></tr></thead><tbody>@foreach($invoice->items as $item)<tr><td>{{ $item->description }}</td><td>{{ $item->quantity }}</td><td>{{ number_format($item->unit_price, 2, ',', ' ') }} FCFA</td><td>{{ number_format($item->discount_amount, 2, ',', ' ') }} FCFA</td><td>{{ $item->tax_rate }} %</td><td>{{ number_format($item->tax_amount, 2, ',', ' ') }} FCFA</td><td>{{ number_format($item->total, 2, ',', ' ') }} FCFA</td></tr>@endforeach</tbody></table></div>
    <div class="quote-summary"><span>Sous-total HT <b>{{ number_format($invoice->subtotal, 2, ',', ' ') }} FCFA</b></span><span>Remise <b>{{ number_format($invoice->discount_amount, 2, ',', ' ') }} FCFA</b></span><span>TVA <b>{{ number_format($invoice->tax_amount, 2, ',', ' ') }} FCFA</b></span><strong>Total TTC <b>{{ number_format($invoice->total, 2, ',', ' ') }} FCFA</b></strong></div>
    <div class="mt-6 grid gap-4 border-t pt-5 sm:grid-cols-4"><p>Montant total<strong class="block">{{ number_format($invoice->total, 2, ',', ' ') }} FCFA</strong></p><p>Montant payé<strong class="block">{{ number_format($invoice->amount_paid, 2, ',', ' ') }} FCFA</strong></p><p>Solde restant<strong class="block">{{ number_format($invoice->balance_due, 2, ',', ' ') }} FCFA</strong></p><p>Statut financier <strong class="block">{{ $invoice->financialStatusLabel() }}</strong></p></div>
    @if(auth()->user()->hasPermission('payment.view'))
    <section class="mt-8 border-t pt-6">
        <div class="panel-heading"><div><p class="section-kicker">Encaissements</p><h3>Paiements</h3></div><div class="text-right"><p>Total des paiements</p><strong>{{ number_format($invoice->payments->sum('amount'), 2, ',', ' ') }} FCFA</strong></div></div>
        @if((float) $invoice->balance_due <= 0)
            <p class="mb-4 rounded-xl bg-emerald-50 p-4 font-semibold text-emerald-800">✓ Facture payée</p>
        @else
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><p>Solde restant : <strong>{{ number_format($invoice->balance_due, 2, ',', ' ') }} FCFA</strong></p>@if(auth()->user()->hasPermission('payment.create'))<a class="button-primary" href="{{ route('invoices.payments.index', $invoice) }}">Enregistrer un paiement</a>@endif</div>
        @endif
        @if($invoice->payments->isEmpty())<p class="muted-copy">Aucun paiement enregistré.</p>
        @else<div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Mode de paiement</th><th>Référence</th><th>Montant</th><th>Opérateur</th><th>Reçu</th></tr></thead><tbody>@foreach($invoice->payments as $payment)<tr><td>{{ $payment->paid_at->format('d/m/Y') }}</td><td>{{ $payment->paymentMethod->name }}</td><td>{{ $payment->reference ?: '—' }}</td><td>{{ number_format($payment->amount, 2, ',', ' ') }} FCFA</td><td>{{ $payment->receivedBy?->name ?? 'Compte supprimé' }}</td><td>@if(auth()->user()->hasPermission('receipt.view'))<a class="text-[#28745d] underline" href="{{ route('payments.receipt', $payment) }}">{{ $payment->receipt_number ?: 'Voir le reçu' }}</a>@else{{ $payment->receipt_number ?: '—' }}@endif</td></tr>@endforeach</tbody></table></div>@endif
    </section>
    @endif
    @if($invoice->notes)<div class="mt-6"><p class="section-kicker">Notes</p><p class="whitespace-pre-line">{{ $invoice->notes }}</p></div>@endif
</section>
@endsection
