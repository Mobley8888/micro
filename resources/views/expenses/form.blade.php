@extends('layouts.app', ['heading' => 'Nouvelle dépense', 'eyebrow' => 'Finances'])
@section('content')
<div class="mx-auto max-w-5xl">
    <div class="mb-7">
        <p class="section-kicker">Finances</p>
        <h2 class="hero-title">Nouvelle dépense.</h2>
        <p class="muted-copy">Enregistrez une dépense et son impact sur la trésorerie.</p>
    </div>

    @if($errors->any())
        <div class="flash-error" role="alert"><p class="font-bold">Vérifiez les champs signalés.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form id="expense-form" method="post" enctype="multipart/form-data" action="{{ route('expenses.store') }}" class="panel form-grid">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
        @if(auth()->user()->isSuperAdmin())<input type="hidden" name="company_id" value="{{ old('company_id', $companyId) }}">@endif

        @if(auth()->user()->isSuperAdmin())
            <div class="form-section">
                <h3>Entreprise</h3>
                <div class="form-fields md:grid-cols-2">
                    <label>Entreprise
                        <select class="search-input" name="company_id" form="expense-company-filter" required onchange="this.form.requestSubmit()">
                            <option value="">Sélectionner une entreprise</option>
                            @foreach($companies as $company)<option value="{{ $company->id }}" @selected((string) $companyId === (string) $company->id)>{{ $company->name }}</option>@endforeach
                        </select>
                        @error('company_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <form id="expense-company-filter" method="get" action="{{ route('expenses.create') }}"></form>
                </div>
            </div>
        @endif

        <section class="form-section" aria-labelledby="expense-details-heading">
            <h3 id="expense-details-heading">Informations de la dépense</h3>
            <div class="form-fields md:grid-cols-2">
                <label>Catégorie
                    <select class="search-input" name="expense_category_id" required>
                        <option value="">Sélectionner une catégorie</option>
                        @foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('expense_category_id') === $category->id)>{{ $category->name }}</option>@endforeach
                    </select>
                    @if($categories->isEmpty())
                        <span class="text-sm font-normal text-amber-800">Aucune catégorie disponible.</span>
                        @if(auth()->user()->hasPermission('expense.create'))<a class="text-sm font-semibold text-[#28745d] underline" href="{{ route('expenses.index') }}#categories">+ Nouvelle catégorie</a>@endif
                    @endif
                    @error('expense_category_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Montant (FCFA)
                    <input class="search-input" type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0 FCFA" value="{{ old('amount') }}" required>
                    @error('amount')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Date
                    <input class="search-input" type="date" name="expense_date" value="{{ old('expense_date', today()->toDateString()) }}" required>
                    @error('expense_date')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Référence <span class="font-normal text-slate-500">(facultatif)</span>
                    <input class="search-input" name="reference" maxlength="255" placeholder="Référence de la dépense" value="{{ old('reference') }}">
                    @error('reference')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="md:col-span-2">Description
                    <textarea class="search-input" name="description" rows="3" maxlength="2000" placeholder="Décrivez la dépense" required>{{ old('description') }}</textarea>
                    @error('description')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label>Fournisseur <span class="font-normal text-slate-500">(facultatif)</span>
                    <input class="search-input" name="supplier" maxlength="255" placeholder="Nom du fournisseur" value="{{ old('supplier') }}" autocomplete="organization">
                    <span class="text-xs font-normal text-slate-500">Le répertoire fournisseurs n’est pas configuré ; saisissez le nom librement.</span>
                    @error('supplier')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
            </div>
        </section>

        <section class="form-section" aria-labelledby="payment-details-heading">
            <h3 id="payment-details-heading">Règlement et trésorerie</h3>
            <div class="form-fields md:grid-cols-2">
                <label>Moyen de paiement
                    <select id="payment-method" class="search-input" name="payment_method_id" required>
                        <option value="">Sélectionner un moyen de paiement</option>
                        @foreach($methods as $method)<option value="{{ $method->id }}" data-code="{{ $method->code }}" @selected(old('payment_method_id') === $method->id)>{{ $method->name }}</option>@endforeach
                    </select>
                    @error('payment_method_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <div id="cash-session-field" class="hidden">
                    <label>Session de caisse
                        <select id="cash-session" class="search-input" name="cash_session_id">
                            <option value="">Sélectionner la session de caisse</option>
                            @foreach($sessions as $session)<option value="{{ $session->id }}" @selected(old('cash_session_id') === $session->id)>{{ $session->cashRegister->name }} — {{ $session->openedBy->name ?? 'Session ouverte' }}</option>@endforeach
                        </select>
                        @error('cash_session_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                    </label>
                    @if($sessions->isEmpty())<p class="mt-2 text-sm text-amber-800">Aucune session de caisse ouverte.@if(auth()->user()->hasPermission('cash.open')) <a class="font-semibold underline" href="{{ route('cash.my-session') }}">Ouvrir ma caisse</a>@endif</p>@endif
                </div>
                <div id="bank-account-field" class="hidden">
                    <label>Compte bancaire ou de trésorerie
                        <select id="bank-account" class="search-input" name="bank_account_id">
                            <option value="">Sélectionner le compte utilisé</option>
                            @foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('bank_account_id') === $account->id)>{{ $account->name }}{{ $account->bank_name ? ' — '.$account->bank_name : '' }}</option>@endforeach
                        </select>
                        @error('bank_account_id')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                    </label>
                    @if($accounts->isEmpty())<p class="mt-2 text-sm text-amber-800">Aucun compte actif disponible. <a class="font-semibold underline" href="{{ route('banks.index') }}">Configurer un compte</a></p>@endif
                </div>
                @if($methods->isEmpty())<p class="text-sm text-amber-800">Aucun moyen de paiement actif n’est configuré pour cette entreprise.</p>@endif
            </div>
        </section>

        <section class="form-section" aria-labelledby="attachment-heading">
            <h3 id="attachment-heading">Justificatif et notes</h3>
            <div class="form-fields md:grid-cols-2">
                <label class="md:col-span-2">Justificatif
                    <input id="expense-attachment" class="search-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                    <span class="text-xs font-normal text-slate-500">PDF, JPEG ou PNG — 5 Mo maximum. <span id="attachment-name">Aucun fichier choisi.</span></span>
                    @error('attachment')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="md:col-span-2">Notes <span class="font-normal text-slate-500">(facultatif)</span>
                    <textarea class="search-input" name="notes" rows="3" maxlength="2000" placeholder="Informations complémentaires">{{ old('notes') }}</textarea>
                    @error('notes')<span class="text-sm font-normal text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
            </div>
        </section>

        <div class="form-actions">
            <a class="button-secondary" href="{{ route('expenses.index') }}">Annuler</a>
            <button id="expense-submit" class="button-primary" type="submit" @disabled($categories->isEmpty() || $methods->isEmpty())>Enregistrer</button>
        </div>
    </form>
</div>
<script>
(() => {
    const form = document.getElementById('expense-form');
    const method = document.getElementById('payment-method');
    const cashField = document.getElementById('cash-session-field');
    const bankField = document.getElementById('bank-account-field');
    const cashSelect = document.getElementById('cash-session');
    const bankSelect = document.getElementById('bank-account');
    const attachment = document.getElementById('expense-attachment');
    const attachmentName = document.getElementById('attachment-name');
    const togglePaymentFields = () => {
        const isCash = method?.selectedOptions[0]?.dataset.code === 'cash';
        cashField?.classList.toggle('hidden', !isCash);
        bankField?.classList.toggle('hidden', !method?.value || isCash);
        if (cashSelect) { cashSelect.required = Boolean(isCash); cashSelect.disabled = !isCash; }
        if (bankSelect) { bankSelect.required = Boolean(method?.value && !isCash); bankSelect.disabled = !method?.value || isCash; }
    };
    method?.addEventListener('change', togglePaymentFields);
    togglePaymentFields();
    attachment?.addEventListener('change', () => { attachmentName.textContent = attachment.files?.[0]?.name || 'Aucun fichier choisi.'; });
    form?.addEventListener('submit', () => {
        const button = document.getElementById('expense-submit');
        if (form.checkValidity() && button) { button.disabled = true; button.textContent = 'Enregistrement…'; }
    });
})();
</script>
@endsection
