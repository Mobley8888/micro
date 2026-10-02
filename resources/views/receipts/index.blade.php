@extends('layouts.app', ['heading' => 'Reçus', 'eyebrow' => 'Opérations financières'])
@section('content')<div class="mb-8">
    <p class="section-kicker">Encaissements</p>
    <h2 class="hero-title">Les reçus, toujours disponibles.</h2>
    <p class="muted-copy">Retrouvez les justificatifs générés après chaque paiement.</p>
</div>
<section class="panel">
    <div class="empty-state"><span>▧</span>
        <p>Aucun reçu disponible</p><small>Les reçus seront générés automatiquement avec les paiements.</small>
    </div>
</section>@endsection