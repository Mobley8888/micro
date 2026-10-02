@extends('layouts.app', ['heading' => 'Entreprises', 'eyebrow' => 'Administration'])
@section('content')
<div class="mb-6 flex items-center justify-between"><div><p class="section-kicker">Organisation</p><h2 class="hero-title">Entreprises</h2></div><a class="button-primary" href="{{ route('admin.companies.create') }}">Créer une entreprise</a></div>
<div class="panel overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Entreprise</th><th class="p-3">Email</th><th class="p-3">Utilisateurs</th><th class="p-3">Statut</th><th class="p-3"></th></tr></thead><tbody>
@forelse ($companies as $company)
<tr class="border-t"><td class="p-3"><a class="text-link" href="{{ route('admin.companies.show', $company) }}">{{ $company->legal_name ?: $company->name }}</a></td><td class="p-3">{{ $company->email ?: '—' }}</td><td class="p-3">{{ $company->users_count }}</td><td class="p-3">{{ $company->is_active ? 'Active' : 'Inactive' }}</td><td class="p-3"><a class="text-link" href="{{ route('admin.companies.edit', $company) }}">Modifier</a></td></tr>
@empty<tr><td class="p-4" colspan="5">Aucune entreprise enregistrée.</td></tr>@endforelse
</tbody></table>{{ $companies->links() }}</div>
@endsection
