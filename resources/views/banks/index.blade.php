@extends('layouts.app', ['heading' => 'Comptes bancaires', 'eyebrow' => 'Finances'])
@section('content')
<div class="mb-7 flex flex-wrap items-end justify-between gap-4">
    <div><p class="section-kicker">Finances</p><h2 class="hero-title">Comptes bancaires.</h2><p class="muted-copy">Suivez les soldes, mouvements et rapprochements de l’entreprise.</p></div>
    @if(auth()->user()->hasPermission('bank.transaction'))<a class="button-primary" href="{{ route('financial-transfers.create') }}">Nouveau transfert</a>@endif
</div>
@if($errors->any())<div class="flash-error" role="alert"><p class="font-bold">Vérifiez les champs signalés.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @forelse($accounts as $account)
        <article class="panel">
            <h3>{{ $account->name }}</h3>
            <p class="muted-copy">{{ $account->bank_name ?: 'Compte bancaire' }} · {{ $account->maskedAccountNumber() ?: 'N° non renseigné' }}</p>
            <p class="my-4 text-2xl font-semibold">{{ number_format($account->balance(), 2, ',', ' ') }} {{ $account->currency }}</p>
            <p class="text-sm text-slate-600">{{ $account->unreconciled_count }} transaction(s) à rapprocher</p>
            <a class="mt-4 inline-block font-semibold text-[#28745d]" href="{{ route('banks.transactions', $account) }}">Transactions et rapprochement →</a>
        </article>
    @empty
        <p class="panel">Aucun compte bancaire configuré.</p>
    @endforelse
</div>
@if(auth()->user()->hasPermission('bank.create'))
    <details class="panel mt-6" @if($errors->any()) open @endif>
        <summary class="cursor-pointer font-semibold">Ajouter un compte bancaire</summary>
        <form method="post" action="{{ route('banks.store') }}" class="mt-5 form-grid">
            @csrf
            @if(auth()->user()->isSuperAdmin())
                <label class="block text-sm font-semibold">Entreprise
                    <select class="search-input mt-2 w-full" name="company_id" required><option value="">Sélectionner une entreprise</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id') === $company->id)>{{ $company->name }}</option>@endforeach</select>
                    @error('company_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
            @endif
            <div class="form-fields md:grid-cols-2">
                <label>Nom du compte<input class="search-input" name="name" placeholder="Ex. Compte courant" value="{{ old('name') }}" required>@error('name')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Banque<input class="search-input" name="bank_name" placeholder="Nom de la banque" value="{{ old('bank_name') }}">@error('bank_name')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Numéro de compte<input class="search-input" name="account_number" placeholder="Numéro masqué dans l’application" value="{{ old('account_number') }}">@error('account_number')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Solde initial (FCFA)<input class="search-input" name="opening_balance" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0 FCFA" value="{{ old('opening_balance', '0.00') }}" required>@error('opening_balance')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Devise<input class="search-input uppercase" name="currency" value="{{ old('currency', 'XAF') }}" maxlength="3" required>@error('currency')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Type de compte<input class="search-input" name="account_type" value="{{ old('account_type', 'current') }}" maxlength="100">@error('account_type')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label class="md:col-span-2">Notes<textarea class="search-input" name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea>@error('notes')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
            </div>
            <div class="form-actions"><button class="button-primary" type="submit">Créer le compte</button></div>
        </form>
    </details>
@endif
@endsection
