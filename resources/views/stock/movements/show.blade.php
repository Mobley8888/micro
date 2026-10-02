@extends('layouts.app', ['heading' => 'Détail du mouvement', 'eyebrow' => 'Stock'])

@section('content')
    <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <p class="section-kicker">Mouvement</p>
            <h2 class="hero-title">Détail du mouvement</h2>
            <p class="muted-copy">Traçabilité et informations associées.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('stock.movements.index') }}" class="button-secondary">Retour</a>
        </div>
    </div>

    <section class="panel">
        <div class="stats-grid">
            <div class="stat-card">
                <p>Produit</p>
                <strong>{{ $movement->product?->name ?? '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Type</p>
                <strong>{{ ucfirst(str_replace('_', ' ', $movement->type)) }}</strong>
            </div>
            <div class="stat-card">
                <p>Direction</p>
                <strong>{{ $movement->direction === 'in' ? 'Entrée' : 'Sortie' }}</strong>
            </div>
            <div class="stat-card">
                <p>Quantité</p>
                <strong>{{ $movement->quantity }}</strong>
            </div>
            <div class="stat-card">
                <p>Entrepôt</p>
                <strong>{{ $movement->warehouse?->name ?? '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Référence</p>
                <strong>{{ $movement->reference ?: '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Date</p>
                <strong>{{ $movement->occurred_at?->format('d/m/Y H:i') }}</strong>
            </div>
            <div class="stat-card">
                <p>Utilisateur</p>
                <strong>{{ $movement->createdBy?->name ?? '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Coût total</p>
                <strong>{{ $movement->total_cost !== null ? number_format((float) $movement->total_cost, 0, ',', ' ') . ' FCFA' : '—' }}</strong>
            </div>
            <div class="stat-card">
                <p>Source</p>
                <strong>{{ $movement->source_type ? class_basename($movement->source_type) : 'Manuel' }}</strong>
            </div>
        </div>
    </section>
@endsection
