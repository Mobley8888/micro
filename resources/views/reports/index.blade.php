@extends('layouts.app', ['heading' => 'Rapports', 'eyebrow' => 'Pilotage financier'])
@section('content')<div class="mb-8">
    <p class="section-kicker">Analyse</p>
    <h2 class="hero-title">Voir ce qui fait avancer l’activité.</h2>
    <p class="muted-copy">Les indicateurs de vente, d’encaissement et de recouvrement seront regroupés ici.</p>
</div>
<div class="stats-grid">
    <div class="stat-card accent-green">
        <p>Chiffre d’affaires</p><strong>0 <em>FCFA</em></strong>
    </div>
    <div class="stat-card accent-blue">
        <p>Encaissements</p><strong>0 <em>FCFA</em></strong>
    </div>
    <div class="stat-card accent-red">
        <p>Impayés</p><strong>0 <em>FCFA</em></strong>
    </div>
</div>@endsection