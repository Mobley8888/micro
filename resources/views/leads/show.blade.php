@extends('layouts.app', ['heading' => 'Fiche prospect', 'eyebrow' => 'CRM commercial'])
@section('content')<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">{{ ucfirst($lead->stage) }}</p>
        <h2 class="hero-title">{{ $lead->name }}</h2>
        <p class="muted-copy">{{ $lead->email ?: 'Aucun email' }} · {{ $lead->phone ?: 'Aucun téléphone' }}</p>
    </div><a class="button-secondary" href="{{ route('leads.edit', $lead) }}">Modifier</a>
</div>
<section class="panel">
    <div class="panel-heading">
        <div>
            <p class="section-kicker">Opportunité</p>
            <h3>{{ number_format($lead->potential_amount, 0, ',', ' ') }} FCFA potentiel</h3>
        </div>
    </div>
    <p class="muted-copy">{{ $lead->notes ?: 'Aucune note enregistrée.' }}</p>
</section>@endsection