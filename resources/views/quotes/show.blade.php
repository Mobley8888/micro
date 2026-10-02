@extends('layouts.app', ['heading' => 'Détail du devis', 'eyebrow' => 'Relation client'])

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
<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end no-print">
    <div>
        <p class="section-kicker">{{ $quote->number }}</p>
        <h2 class="hero-title">Devis {{ $quote->number }}.</h2>
        <p class="muted-copy">{{ $quote->customer->legal_name ?: trim($quote->customer->first_name.' '.$quote->customer->last_name) }} · {{ $quote->issue_date->format('d/m/Y') }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="button-secondary" onclick="window.print()">Imprimer</button>
        <a class="button-secondary" href="{{ route('quotes.export.excel', $quote) }}">Exporter Excel</a>
        @if($quote->status === 'accepted' && $quote->invoice === null && auth()->user()->hasPermission('invoice.create'))
            <form method="post" action="{{ route('quotes.convert-to-invoice', $quote) }}" onsubmit="return confirm('Convertir ce devis accepté en facture ?')">@csrf<button class="button-primary">Convertir en facture</button></form>
        @elseif($quote->invoice && auth()->user()->hasPermission('invoice.view'))
            <a class="button-primary" href="{{ route('invoices.show', $quote->invoice) }}">Voir la facture : {{ $quote->invoice->number }}</a>
        @endif
        @if($quote->status === 'draft')
            <a class="button-secondary" href="{{ route('quotes.edit', $quote) }}">Modifier</a>
            <form method="post" action="{{ route('quotes.send', $quote) }}">@csrf @method('PATCH')<button class="button-primary">Envoyer</button></form>
        @elseif($quote->status === 'sent')
            <form method="post" action="{{ route('quotes.accept', $quote) }}">@csrf @method('PATCH')<button class="button-primary">Accepter</button></form>
            <form method="post" action="{{ route('quotes.reject', $quote) }}">@csrf @method('PATCH')<button class="button-secondary">Refuser</button></form>
        @endif
        @if(in_array($quote->status, ['draft', 'sent']))
            <form method="post" action="{{ route('quotes.destroy', $quote) }}" onsubmit="return confirm('Annuler ce devis ?')">@csrf @method('DELETE')<button class="button-secondary">Annuler</button></form>
        @endif
    </div>
</div>
<section class="panel print-document">
    <div class="panel-heading">
        <div class="flex items-center gap-4">
            @if($quote->company->logo_path)<img src="{{ Storage::url($quote->company->logo_path) }}" alt="Logo {{ $quote->company->name }}" class="max-h-16 max-w-32 object-contain">@endif
            <div><p class="section-kicker">{{ $quote->company->legal_name ?: $quote->company->name }}</p><p>{{ $quote->company->address ?? '' }} {{ $quote->company->city }}</p><p>{{ $quote->company->email }} · {{ $quote->company->phone }}</p></div>
        </div>
        <div class="text-right"><p class="section-kicker">DEVIS</p><h2 class="text-2xl font-bold">{{ $quote->number }}</h2><p>Date : {{ $quote->issue_date->format('d/m/Y') }}</p><p>Échéance : {{ $quote->due_date?->format('d/m/Y') ?? '—' }}</p><p class="no-print mt-2"><span class="status-pill status-neutral">{{ ucfirst($quote->status) }}</span></p></div>
    </div>
    <div class="my-8 rounded-xl bg-[#f5f7f8] p-5">
        <p class="section-kicker">Client</p><strong>{{ $quote->customer->legal_name ?: trim($quote->customer->first_name.' '.$quote->customer->last_name) }}</strong>
        <p>{{ $quote->customer->address }}</p><p>{{ $quote->customer->city }} {{ $quote->customer->country }}</p><p>{{ $quote->customer->email }} · {{ $quote->customer->phone }}</p>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Désignation</th><th>Qté</th><th>Prix unitaire</th><th>Remise</th><th>TVA</th><th>Montant TVA</th><th>Sous-total HT</th><th>Total TTC</th></tr></thead>
            <tbody>@foreach($quote->items as $item)<tr>
                <td>{{ $item->description }}@if($item->product?->code)<small class="block">{{ $item->product->code }}</small>@endif</td>
                <td>{{ $item->quantity }}</td><td>{{ number_format($item->unit_price, 2, ',', ' ') }} FCFA</td>
                <td>{{ number_format($item->discount_amount, 2, ',', ' ') }} FCFA</td><td>{{ $item->tax_rate }} %</td>
                <td>{{ number_format($item->tax_amount, 2, ',', ' ') }} FCFA</td>
                <td>{{ number_format(max(0, $item->quantity * $item->unit_price - $item->discount_amount), 2, ',', ' ') }} FCFA</td>
                <td>{{ number_format($item->total, 2, ',', ' ') }} FCFA</td>
            </tr>@endforeach</tbody>
        </table>
    </div>
    <div class="quote-summary"><span>Sous-total HT <b>{{ number_format($quote->subtotal, 2, ',', ' ') }} FCFA</b></span><span>Remise <b>{{ number_format($quote->discount_amount, 2, ',', ' ') }} FCFA</b></span><span>TVA <b>{{ number_format($quote->tax_amount, 2, ',', ' ') }} FCFA</b></span><strong>Total TTC <b>{{ number_format($quote->total, 2, ',', ' ') }} FCFA</b></strong></div>
    @if($quote->notes)<div class="mt-8"><h3 class="section-kicker">Notes et conditions</h3><p class="whitespace-pre-line">{{ $quote->notes }}</p></div>@endif
</section>
@endsection
