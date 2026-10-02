@extends('layouts.app', ['heading' => 'Caisses', 'eyebrow' => 'Trésorerie'])
@section('content')
<div class="mb-8 flex flex-wrap items-end justify-between gap-4"><div><p class="section-kicker">Espèces</p><h2 class="hero-title">Gestion des caisses.</h2><p class="muted-copy">Sessions, mouvements et clôtures de caisse.</p></div>@if(auth()->user()->hasPermission('cash.create'))<a class="button-primary" href="{{ route('cash-registers.create') }}">Créer une caisse</a>@endif</div>
<section class="panel">
@if($registers->isEmpty())<div class="empty-state"><span>▣</span><p>Aucune caisse configurée</p><small>Créez une caisse pour enregistrer les paiements espèces.</small></div>
@else<div class="table-wrap"><table class="data-table"><thead><tr><th>Caisse</th><th>Code</th><th>Entreprise</th><th>État</th><th>Session actuelle</th><th></th></tr></thead><tbody>
@foreach($registers as $cashRegister)@php($session = $cashRegister->sessions->first())<tr><td><strong>{{ $cashRegister->name }}</strong><small class="block text-slate-500">{{ $cashRegister->description }}</small></td><td>{{ $cashRegister->code }}</td><td>{{ $cashRegister->company->legal_name ?: $cashRegister->company->name }}</td><td>{{ $cashRegister->is_active ? 'Active' : 'Inactive' }}</td><td>{{ $session ? 'Ouverte depuis '.$session->opened_at->format('d/m/Y H:i') : 'Aucune session ouverte' }}</td><td><a class="button-secondary" href="{{ route('cash-registers.show', $cashRegister) }}">Consulter</a></td></tr>@endforeach
</tbody></table></div><div class="mt-5">{{ $registers->links() }}</div>@endif
</section>
@endsection

