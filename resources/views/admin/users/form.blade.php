@extends('layouts.app', ['heading' => $user->exists ? 'Modifier un utilisateur' : 'Créer un utilisateur', 'eyebrow' => 'Administration'])
@section('content')
<div class="mb-8">
    <p class="section-kicker">Accès et rôles</p>
    <h2 class="hero-title">{{ $user->exists ? 'Modifier un utilisateur.' : 'Ajouter un utilisateur.' }}</h2>
    <p class="muted-copy">Gérez les coordonnées, l’entreprise et les accès au compte.</p>
</div>
<form class="panel form-grid" method="post" action="{{ $user->exists ? route(request()->routeIs('admin.users.*') ? 'admin.users.update' : 'users.update', $user) : route(request()->routeIs('admin.users.*') ? 'admin.users.store' : 'users.store') }}">
    @csrf
    @if($user->exists)
        @method('put')
    @endif

    <div class="form-section">
        <p class="section-kicker">Profil</p>
        <h3>Informations personnelles</h3>
        <div class="form-fields">
            <label>Nom complet
                <input name="name" value="{{ old('name', $user->name) }}" required autocomplete="name">
                @error('name')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
            <label>Email
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email">
                @error('email')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
            <label>Téléphone
                <input name="phone" value="{{ old('phone', $user->phone) }}" autocomplete="tel">
                @error('phone')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
        </div>
    </div>

    <div class="form-section">
        <p class="section-kicker">Accès</p>
        <h3>Affectation</h3>
        <div class="form-fields">
            @if(!$isCompanyScoped)
                <label>Entreprise
                    <select name="company_id" id="company_id" required>
                        <option value="">Choisir une entreprise…</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}" @selected(old('company_id', $user->company_id) === $company->id)>{{ $company->legal_name ?: $company->name }}</option>
                        @endforeach
                    </select>
                    @error('company_id')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                @if ($user->exists && $user->isSuperAdmin())
                    <p class="rounded-lg bg-slate-50 p-3 text-sm">Rôle global conservé : super-administrator</p>
                @else
                    <label>Rôle
                        <select name="role_id" id="role_id" required>
                            <option value="">Choisir un rôle…</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->id }}" data-company="{{ $role->company_id }}" @selected((string) old('role_id', $user->roles->first()?->id) === (string) $role->id)>{{ $role->slug }} — {{ $role->company?->legal_name ?: $role->company?->name }}</option>
                            @endforeach
                        </select>
                        @error('role_id')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endif
            @else
                <p class="rounded-lg bg-slate-50 p-3 text-sm">Entreprise : {{ auth()->user()->company?->legal_name ?: auth()->user()->company?->name }}<br>Rôle attribué : operator</p>
            @endif
        </div>
    </div>

    <div class="form-section">
        <p class="section-kicker">Sécurité</p>
        <h3>Identifiants</h3>
        <div class="form-fields">
            <label>Mot de passe {{ $user->exists ? '(laisser vide pour conserver)' : '' }}
                <input type="password" name="password" @required(!$user->exists) autocomplete="new-password">
                @error('password')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
            <label>Confirmer le mot de passe
                <input type="password" name="password_confirmation" @required(!$user->exists) autocomplete="new-password">
                @error('password_confirmation')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
            </label>
        </div>
    </div>

    <div class="form-section">
        <p class="section-kicker">État du compte</p>
        <h3>Statut</h3>
        <label class="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->exists ? $user->is_active : true))>
            Compte actif
        </label>
        @error('is_active')<span class="text-red-700" role="alert">{{ $message }}</span>@enderror
    </div>

    <div class="form-actions">
        <a class="button-secondary" href="{{ route(request()->routeIs('admin.users.*') ? 'admin.users.index' : 'users.index') }}">Annuler</a>
        <button class="button-primary">Enregistrer</button>
    </div>
</form>
@if(!$isCompanyScoped && !($user->exists && $user->isSuperAdmin()))
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
