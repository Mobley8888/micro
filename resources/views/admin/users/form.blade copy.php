@extends('layouts.app', [
'heading' => $user->exists ? 'Modifier un utilisateur' : 'Créer un utilisateur',
'eyebrow' => 'Administration',
])

@section('content')
<div class="panel max-w-4xl">
    <form
        method="post"
        action="{{ $user->exists
            ? route(request()->routeIs('admin.users.*') ? 'admin.users.update' : 'users.update', $user)
            : route(request()->routeIs('admin.users.*') ? 'admin.users.store' : 'users.store') }}"
        class="grid gap-5 md:grid-cols-2">
        @csrf
        @if ($user->exists)
        @method('put')
        @endif

        {{-- Nom complet --}}
        <div class="grid gap-1 text-sm">
            <label for="name" class="font-semibold">Nom complet</label>
            <input
                id="name"
                name="name"
                class="rounded-lg border p-3"
                value="{{ old('name', $user->name) }}"
                required>
            @error('name')
            <span class="text-red-700">{{ $message }}</span>
            @enderror
        </div>

        {{-- Email --}}
        <div class="grid gap-1 text-sm">
            <label for="email" class="font-semibold">Email</label>
            <input
                id="email"
                type="email"
                name="email"
                class="rounded-lg border p-3"
                value="{{ old('email', $user->email) }}"
                required>
            @error('email')
            <span class="text-red-700">{{ $message }}</span>
            @enderror
        </div>

        {{-- Téléphone --}}
        <div class="grid gap-1 text-sm">
            <label for="phone" class="font-semibold">Téléphone</label>
            <input
                id="phone"
                name="phone"
                class="rounded-lg border p-3"
                value="{{ old('phone', $user->phone) }}">
        </div>

        @if (!$isCompanyScoped)
        {{-- Entreprise --}}
        <div class="grid gap-1 text-sm">
            <label for="company_id" class="font-semibold">Entreprise</label>
            <select id="company_id" name="company_id" class="rounded-lg border p-3" required>
                <option value="">Choisir…</option>
                @foreach ($companies as $company)
                <option
                    value="{{ $company->id }}"
                    @selected(old('company_id', $user->company_id) === $company->id)
                    >
                    {{ $company->legal_name ?: $company->name }}
                </option>
                @endforeach
            </select>
            @error('company_id')
            <span class="text-red-700">{{ $message }}</span>
            @enderror
        </div>

        @if ($user->exists && $user->isSuperAdmin())
        {{-- Rôle global conservé --}}
        <div class="grid gap-1 text-sm">
            <p class="rounded-lg bg-slate-50 p-3 font-semibold">
                Rôle global conservé : super-administrator
            </p>
        </div>
        @else
        {{-- Rôle --}}
        <div class="grid gap-1 text-sm">
            <label for="role_id" class="font-semibold">Rôle</label>
            <select id="role_id" name="role_id" class="rounded-lg border p-3" required>
                @foreach ($roles as $role)
                <option
                    value="{{ $role->id }}"
                    data-company="{{ $role->company_id }}"
                    @selected((string) old('role_id', $user->roles->first()?->id) === (string) $role->id)
                    >
                    {{ $role->slug }} — {{ $role->company?->legal_name ?: $role->company?->name }}
                </option>
                @endforeach
            </select>
            @error('role_id')
            <span class="text-red-700">{{ $message }}</span>
            @enderror
        </div>
        @endif
        @else
        {{-- Entreprise / rôle imposés --}}
        <div class="grid gap-1 text-sm md:col-span-2">
            <p class="rounded-lg bg-slate-50 p-3 font-semibold">
                Entreprise : {{ auth()->user()->company?->legal_name ?: auth()->user()->company?->name }}
                <br>
                Rôle attribué : operator
            </p>
        </div>
        @endif

        {{-- Mot de passe --}}
        <div class="grid gap-1 text-sm">
            <label for="password" class="font-semibold">
                Mot de passe {{ $user->exists ? '(laisser vide pour conserver)' : '' }}
            </label>
            <input
                id="password"
                type="password"
                name="password"
                class="rounded-lg border p-3"
                @required(!$user->exists)
            autocomplete="new-password"
            >
            @error('password')
            <span class="text-red-700">{{ $message }}</span>
            @enderror
        </div>

        {{-- Confirmation mot de passe --}}
        <div class="grid gap-1 text-sm">
            <label for="password_confirmation" class="font-semibold">Confirmer le mot de passe</label>
            <input
                id="password_confirmation"
                type="password"
                name="password_confirmation"
                class="rounded-lg border p-3"
                @required(!$user->exists)
            autocomplete="new-password"
            >
        </div>

        {{-- Compte actif --}}
        <div class="flex items-center gap-2 text-sm font-semibold md:col-span-2">
            <input
                id="is_active"
                type="checkbox"
                name="is_active"
                value="1"
                @checked(old('is_active', $user->exists ? $user->is_active : true))
            >
            <label for="is_active">Compte actif</label>
        </div>

        {{-- Actions --}}
        <div class="flex gap-3 md:col-span-2">
            <button class="button-primary">Enregistrer</button>
            <a
                class="button-secondary"
                href="{{ route(request()->routeIs('admin.users.*') ? 'admin.users.index' : 'users.index') }}">
                Annuler
            </a>
        </div>
    </form>
</div>

@if (!$isCompanyScoped && !($user->exists && $user->isSuperAdmin()))
<script>
    const companySelect = document.getElementById('company_id');
    const roleSelect = document.getElementById('role_id');

    const filterRoles = () => {
        for (const option of roleSelect.options) {
            option.hidden = option.dataset.company !== companySelect.value;
        }
        if (roleSelect.selectedOptions.length && roleSelect.selectedOptions[0].hidden) {
            roleSelect.value = '';
        }
    };

    companySelect.addEventListener('change', filterRoles);
    filterRoles();
</script>
@endif
@endsection