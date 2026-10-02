@extends('layouts.app', ['heading' => 'Entreprise', 'eyebrow' => 'Administration'])
@section('content')
<div class="panel max-w-3xl">
<div class="flex items-start justify-between"><div><p class="section-kicker">{{ $company->is_active ? 'Active' : 'Inactive' }}</p><h2 class="hero-title">{{ $company->legal_name ?: $company->name }}</h2></div><a class="button-primary" href="{{ route('admin.companies.edit', $company) }}">Modifier</a></div>
<dl class="mt-6 grid gap-4 md:grid-cols-2">@foreach (['Nom commercial' => $company->name, 'Email' => $company->email, 'Téléphone' => $company->phone, 'Identifiant fiscal' => $company->tax_number, 'RCCM' => $company->registration_number, 'Adresse / ville' => $company->city, 'Pays' => $company->country, 'Utilisateurs' => $company->users_count] as $label => $value)<div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>@endforeach</dl>
<div class="mt-6 flex gap-3">@if ($company->is_active)<form method="post" action="{{ route('admin.companies.deactivate', $company) }}">@csrf @method('patch')<button class="button-secondary">Désactiver</button></form>@else<form method="post" action="{{ route('admin.companies.activate', $company) }}">@csrf @method('patch')<button class="button-primary">Activer</button></form>@endif<a class="button-secondary" href="{{ route('admin.companies.index') }}">Retour</a></div>
</div>
@endsection
