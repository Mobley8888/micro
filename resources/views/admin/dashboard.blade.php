@extends('layouts.app', ['heading' => 'Administration', 'eyebrow' => 'Espace opérateur'])
@section('content')
<div class="mb-8">
    <p class="section-kicker">Vue d’ensemble</p>
    <h2 class="hero-title">Administration MICRO</h2>
    <p class="muted-copy">Suivi des entreprises et des accès utilisateurs.</p>
</div>
<div class="stats-grid">
    @if (! is_null($companiesCount))
    <div class="stat-card accent-green"><p>Entreprises</p><strong>{{ $companiesCount }}</strong><small>Comptes enregistrés</small></div>
    @endif
    <div class="stat-card accent-blue"><p>Utilisateurs</p><strong>{{ $usersCount }}</strong><small>{{ auth()->user()->isSuperAdmin() ? 'Toutes entreprises' : 'Votre entreprise' }}</small></div>
    <div class="stat-card accent-green"><p>Comptes actifs</p><strong>{{ $activeUsersCount }}</strong><small>Accès autorisés</small></div>
    @if (! is_null($companiesCount))
    <div class="stat-card accent-red"><p>Comptes inactifs</p><strong>{{ $inactiveUsersCount }}</strong><small>Accès suspendus</small></div>
    @endif
</div>
<section class="panel mt-8">
    <div class="panel-heading"><div><p class="section-kicker">Pilotage financier</p><h3>Créances et encaissements</h3></div><a class="button-secondary" href="{{ route('receivables.index') }}">Voir les créances</a></div>
    <div class="stats-grid">
        <div class="stat-card accent-green"><p>Total facturé</p><strong>{{ number_format($financialStats['invoiced'], 0, ',', ' ') }} <em>FCFA</em></strong></div>
        <div class="stat-card accent-blue"><p>Total encaissé</p><strong>{{ number_format($financialStats['collected'], 0, ',', ' ') }} <em>FCFA</em></strong></div>
        <div class="stat-card accent-red"><p>Reste à recouvrer</p><strong>{{ number_format($financialStats['balance'], 0, ',', ' ') }} <em>FCFA</em></strong><small>{{ $financialStats['unpaid'] }} impayée(s), {{ $financialStats['partial'] }} partielle(s)</small></div>
        <div class="stat-card accent-orange"><p>Total en retard</p><strong>{{ number_format($financialStats['overdue'], 0, ',', ' ') }} <em>FCFA</em></strong><small>{{ $financialStats['overdueCount'] }} facture(s) échue(s)</small></div>
    </div>
</section>
<div class="mt-8 grid gap-6 md:grid-cols-2">
    @if (auth()->user()->isSuperAdmin())
    <a class="panel text-link" href="{{ route('admin.companies.index') }}"><h3>Entreprises</h3><p class="muted-copy">Créer et gérer les entreprises.</p></a>
    <a class="panel text-link" href="{{ route('admin.users.index') }}"><h3>Utilisateurs</h3><p class="muted-copy">Gérer les comptes et les rôles.</p></a>
    @else
    <a class="panel text-link" href="{{ route('users.index') }}"><h3>Utilisateurs</h3><p class="muted-copy">Gérer les opérateurs de votre entreprise.</p></a>
    @endif
</div>
@endsection
