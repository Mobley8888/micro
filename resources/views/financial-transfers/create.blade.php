@extends('layouts.app', ['heading' => 'Nouveau transfert', 'eyebrow' => 'Finances'])
@section('content')
<div class="mx-auto max-w-5xl">
    <div class="mb-7"><p class="section-kicker">Finances</p><h2 class="hero-title">Nouveau transfert.</h2><p class="muted-copy">Transférez des fonds entre une caisse et un compte bancaire.</p></div>
    @if($errors->any())<div class="flash-error" role="alert"><p class="font-bold">Vérifiez les champs signalés.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(auth()->user()->isSuperAdmin())
        <form id="transfer-company-filter" method="get" action="{{ route('financial-transfers.create') }}" class="panel mb-5 max-w-xl">
            <label class="block text-sm font-semibold">Entreprise
                <select class="search-input mt-2 w-full" name="company_id" required onchange="this.form.requestSubmit()"><option value="">Sélectionner une entreprise</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected((string) $companyId === (string) $company->id)>{{ $company->name }}</option>@endforeach</select>
            </label>
        </form>
    @endif
    <form id="transfer-form" method="post" action="{{ route('financial-transfers.store') }}" class="panel form-grid">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        @if(auth()->user()->isSuperAdmin())<input type="hidden" name="company_id" value="{{ $companyId }}">@endif
        <section class="form-section">
            <h3>Comptes du transfert</h3>
            <div class="form-fields md:grid-cols-2">
                <label>Compte source
                    <select id="from-type" class="search-input" name="from_type" required><option value="cash" @selected(old('from_type', 'cash') === 'cash')>Caisse</option><option value="bank" @selected(old('from_type') === 'bank')>Compte bancaire</option></select>
                    @error('from_type')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Session de caisse
                    <select class="search-input" name="cash_session_id" required><option value="">Sélectionner la session ouverte</option>@foreach($sessions as $session)<option value="{{ $session->id }}" @selected(old('cash_session_id') === $session->id)>{{ $session->cashRegister->name }} — {{ $session->openedBy->name ?? 'Session ouverte' }}</option>@endforeach</select>
                    @error('cash_session_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                    @if($sessions->isEmpty())<span class="text-sm font-normal text-amber-800">Aucune session de caisse ouverte.@if(auth()->user()->hasPermission('cash.open')) <a class="font-semibold underline" href="{{ route('cash.my-session') }}">Ouvrir ma caisse</a>@endif</span>@endif
                </label>
                <label id="from-bank-field" class="hidden">Compte bancaire source
                    <select class="search-input" name="from_id"><option value="">Sélectionner le compte source</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('from_id') === $account->id)>{{ $account->name }}{{ $account->bank_name ? ' — '.$account->bank_name : '' }}</option>@endforeach</select>
                    @error('from_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Compte destination
                    <select id="to-type" class="search-input" name="to_type" required><option value="bank" @selected(old('to_type', 'bank') === 'bank')>Compte bancaire</option><option value="cash" @selected(old('to_type') === 'cash')>Caisse</option></select>
                    @error('to_type')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label id="to-bank-field">Compte bancaire destination
                    <select class="search-input" name="to_id"><option value="">Sélectionner le compte destination</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('to_id') === $account->id)>{{ $account->name }}{{ $account->bank_name ? ' — '.$account->bank_name : '' }}</option>@endforeach</select>
                    @error('to_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                @if($accounts->isEmpty())<p class="text-sm text-amber-800">Aucun compte bancaire actif disponible. <a class="font-semibold underline" href="{{ route('banks.index') }}">Configurer un compte</a></p>@endif
            </div>
        </section>
        <section class="form-section">
            <h3>Détails du transfert</h3>
            <div class="form-fields md:grid-cols-2">
                <label>Montant (FCFA)<input class="search-input" type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0 FCFA" value="{{ old('amount') }}" required>@error('amount')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Date<input class="search-input" type="datetime-local" name="transferred_at" value="{{ old('transferred_at', now()->format('Y-m-d\\TH:i')) }}" required>@error('transferred_at')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label>Référence <span class="font-normal text-slate-500">(facultatif)</span><input class="search-input" name="reference" maxlength="255" placeholder="Référence du transfert" value="{{ old('reference') }}">@error('reference')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
                <label class="md:col-span-2">Description<textarea class="search-input" name="description" rows="3" maxlength="2000" placeholder="Motif ou détails du transfert">{{ old('description') }}</textarea>@error('description')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror</label>
            </div>
        </section>
        <div class="form-actions"><a class="button-secondary" href="{{ route('banks.index') }}">Annuler</a><button id="transfer-submit" class="button-primary" type="submit">Enregistrer le transfert</button></div>
    </form>
</div>
<script>
(() => {
    const fromType = document.getElementById('from-type');
    const toType = document.getElementById('to-type');
    const fromBank = document.getElementById('from-bank-field');
    const toBank = document.getElementById('to-bank-field');
    const toggleTransferFields = () => {
        const sourceCash = fromType.value === 'cash';
        const destinationCash = toType.value === 'cash';
        fromBank.classList.toggle('hidden', sourceCash);
        toBank.classList.toggle('hidden', destinationCash);
        fromBank.querySelector('select').disabled = sourceCash;
        fromBank.querySelector('select').required = !sourceCash;
        toBank.querySelector('select').disabled = destinationCash;
        toBank.querySelector('select').required = !destinationCash;
    };
    fromType.addEventListener('change', toggleTransferFields);
    toType.addEventListener('change', toggleTransferFields);
    toggleTransferFields();
    document.getElementById('transfer-form').addEventListener('submit', event => {
        if (fromType.value === toType.value) { event.preventDefault(); fromType.focus(); alert('Le transfert doit relier une caisse et une banque.'); return; }
        const button = document.getElementById('transfer-submit');
        if (event.currentTarget.checkValidity()) { button.disabled = true; button.textContent = 'Enregistrement…'; }
    });
})();
</script>
@endsection
