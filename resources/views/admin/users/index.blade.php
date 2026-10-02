@extends('layouts.app', ['heading' => 'Utilisateurs', 'eyebrow' => 'Administration'])
@section('content')
<div class="mb-6 flex items-center justify-between"><div><p class="section-kicker">Accès et rôles</p><h2 class="hero-title">Utilisateurs</h2></div><a class="button-primary" href="{{ route(request()->routeIs('admin.users.*') ? 'admin.users.create' : 'users.create') }}">Créer un utilisateur</a></div>
<div class="panel overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-3">Nom</th><th class="p-3">Email</th>@if(auth()->user()->isSuperAdmin())<th class="p-3">Entreprise</th>@endif<th class="p-3">Rôle</th><th class="p-3">Statut</th><th class="p-3">Créé le</th><th class="p-3"></th></tr></thead><tbody>
@forelse($users as $managedUser)
@php($role = $managedUser->roles->first()?->slug)
<tr class="border-t"><td class="p-3"><a class="text-link" href="{{ route(request()->routeIs('admin.users.*') ? 'admin.users.show' : 'users.show', $managedUser) }}">{{ $managedUser->name }}</a></td><td class="p-3">{{ $managedUser->email }}</td>@if(auth()->user()->isSuperAdmin())<td class="p-3">{{ $managedUser->company?->legal_name ?: $managedUser->company?->name ?: '—' }}</td>@endif<td class="p-3">{{ $role === 'super-administrator' ? 'Super administrateur (super-administrator)' : ($role ?: 'operator') }}</td><td class="p-3">{{ $managedUser->is_active ? 'Actif' : 'Inactif' }}</td><td class="p-3">{{ $managedUser->created_at?->format('d/m/Y') ?: '—' }}</td><td class="p-3"><a class="text-link" href="{{ route(request()->routeIs('admin.users.*') ? 'admin.users.edit' : 'users.edit', $managedUser) }}">Modifier</a></td></tr>
@empty<tr><td class="p-4" colspan="7">Aucun utilisateur dans ce périmètre.</td></tr>@endforelse
</tbody></table>{{ $users->links() }}</div>
@endsection
