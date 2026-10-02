@extends('layouts.app', ['heading' => 'Enregistrer un paiement', 'eyebrow' => 'Opérations financières'])
@section('content')
<div class="mb-6"><a href="{{ route('invoices.show', $invoice) }}" class="text-sm font-semibold text-[#28745d]">← Retour à la facture {{ $invoice->number }}</a></div>
<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
    <section class="panel h-fit">
        <p class="section-kicker">Facture {{ $invoice->number }}</p>
        <h2 class="hero-title">Paiement et solde</h2>
        <p class="muted-copy">{{ $invoice->customer->legal_name ?: trim($invoice->customer->first_name.' '.$invoice->customer->last_name) }}</p>
        <dl class="mt-6 space-y-4 border-t pt-5">
            <div class="flex justify-between gap-4"><dt>Total facture</dt><dd class="font-semibold">{{ number_format($invoice->total, 2, ',', ' ') }} FCFA</dd></div>
            <div class="flex justify-between gap-4"><dt>Déjà payé</dt><dd class="font-semibold">{{ number_format($invoice->amount_paid, 2, ',', ' ') }} FCFA</dd></div>
            <div class="flex justify-between gap-4 text-lg"><dt>Solde restant</dt><dd class="font-bold text-[#173b36]">{{ number_format($invoice->balance_due, 2, ',', ' ') }} FCFA</dd></div>
        </dl>
        @if((float) $invoice->balance_due <= 0)
            <p class="mt-6 rounded-xl bg-emerald-50 p-4 font-semibold text-emerald-800">✓ Facture payée. Aucun paiement supplémentaire n’est possible.</p>
        @elseif(auth()->user()->hasPermission('payment.create'))
            @if($paymentMethods->isEmpty())
                <p class="mt-6 rounded-xl bg-amber-50 p-4 text-amber-900">Aucun mode de paiement actif n’est configuré pour cette entreprise.</p>
            @else
                @if($errors->any())<div class="flash-error mt-4" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</div>@endif
                <form method="post" action="{{ route('invoices.payments.store', $invoice) }}" class="mt-6 space-y-4 border-t pt-5">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                <label class="block text-sm font-semibold">Montant *
                    <input class="search-input mt-2 w-full" type="number" name="amount" min="0.01" max="{{ number_format((float) $invoice->balance_due, 2, '.', '') }}" step="0.01" value="{{ old('amount') }}" required>
                    @error('amount')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Mode de paiement *
                    <select class="search-input mt-2 w-full" name="payment_method_id" id="payment_method_id" required><option value="">Choisir un mode</option>@foreach($paymentMethods as $paymentMethod)<option value="{{ $paymentMethod->id }}" data-code="{{ $paymentMethod->code }}" @selected(old('payment_method_id') === $paymentMethod->id)>{{ $paymentMethod->name }}</option>@endforeach</select>
                    @error('payment_method_id')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <div id="bank-account-field" class="{{ old('bank_account_id') ? '' : 'hidden' }}">
                    <label class="block text-sm font-semibold">Compte bancaire destinataire
                        <select class="search-input mt-2 w-full" name="bank_account_id" id="bank_account_id">
                            <option value="">Choisir un compte</option>
                            @foreach($bankAccounts as $bankAccount)
                                <option value="{{ $bankAccount->id }}" @selected(old('bank_account_id') === $bankAccount->id)>{{ $bankAccount->name }} · {{ $bankAccount->bank_name }}</option>
                            @endforeach
                        </select>
                        @error('bank_account_id')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                    </label>
                    @if($bankAccounts->isEmpty())<p class="mt-2 text-sm text-slate-600">Aucun compte bancaire actif; ce paiement sera conservé dans le journal sous son mode de paiement.</p>@endif
                </div>
                <div id="cash-session-field" class="{{ old('cash_session_id') || $paymentMethods->firstWhere('id', old('payment_method_id'))?->code === 'cash' ? '' : 'hidden' }}">
                    <label class="block text-sm font-semibold">Session de caisse *
                        <select class="search-input mt-2 w-full" name="cash_session_id" id="cash_session_id">
                            <option value="">Choisir une session ouverte</option>
                            @foreach($cashSessions as $cashSession)
                                <option value="{{ $cashSession->id }}" @selected(old('cash_session_id') === $cashSession->id)>{{ $cashSession->cashRegister->name }} · ouverte le {{ $cashSession->opened_at->format('d/m/Y H:i') }}</option>
                            @endforeach
                        </select>
                        @error('cash_session_id')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                    </label>
                    @if($cashSessions->isEmpty())<p class="mt-2 text-sm text-amber-800">Aucune session ouverte. @if(auth()->user()->hasPermission('cash.open'))<a class="font-semibold underline" href="{{ route('cash.my-session', ['return_to' => 'invoice']) }}">Ouvrir ma caisse</a>@else Ouvrez une caisse avant d’enregistrer un paiement espèces.@endif</p>@endif
                </div>
                <label class="block text-sm font-semibold">Date du paiement *
                    <input class="search-input mt-2 w-full" type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" required>
                    @error('payment_date')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Référence
                    <input class="search-input mt-2 w-full" type="text" name="reference" maxlength="255" value="{{ old('reference') }}">
                    @error('reference')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold">Notes
                    <textarea class="search-input mt-2 w-full" name="notes" rows="3">{{ old('notes') }}</textarea>
                    @error('notes')<span class="mt-1 block text-sm text-red-700" role="alert">{{ $message }}</span>@enderror
                </label>
                <div class="flex justify-end gap-2"><a class="button-secondary" href="{{ route('invoices.show', $invoice) }}">Annuler</a><button class="button-primary">Enregistrer le paiement</button></div>
                </form>
            @endif
        @endif
    </section>
    <section class="panel">
        <div class="panel-heading"><div><p class="section-kicker">Historique</p><h3>Paiements de cette facture</h3></div><strong>{{ number_format($invoice->payments->sum('amount'), 2, ',', ' ') }} FCFA</strong></div>
        @if($invoice->payments->isEmpty())<div class="empty-state"><p>Aucun paiement enregistré</p><small>Les encaissements apparaîtront ici.</small></div>
        @else<div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Mode</th><th>Référence</th><th>Montant</th></tr></thead><tbody>@foreach($invoice->payments as $payment)<tr><td>{{ $payment->paid_at->format('d/m/Y') }}</td><td>{{ $payment->paymentMethod->name }}@if($payment->cashSession)<small class="block text-slate-500">{{ $payment->cashSession->cashRegister->name }}</small>@endif</td><td>{{ $payment->reference ?: '—' }}</td><td>{{ number_format($payment->amount, 2, ',', ' ') }} FCFA</td></tr>@endforeach</tbody></table></div>@endif
    </section>
</div>
<script>
    const paymentMethodSelect = document.getElementById('payment_method_id');
    const cashSessionField = document.getElementById('cash-session-field');
    const cashSessionSelect = document.getElementById('cash_session_id');
    const bankAccountField = document.getElementById('bank-account-field');
    const bankAccountSelect = document.getElementById('bank_account_id');
    const toggleCashSession = () => {
        const selectedMethod = paymentMethodSelect.selectedOptions[0];
        const isCash = selectedMethod?.dataset.code === 'cash';
        const hasBankAccounts = bankAccountSelect && bankAccountSelect.options.length > 1;
        bankAccountField.classList.toggle('hidden', isCash || !hasBankAccounts);
        bankAccountSelect.required = !isCash && hasBankAccounts;
        cashSessionField.classList.toggle('hidden', !isCash);
        cashSessionSelect.required = isCash;
    };
    paymentMethodSelect.addEventListener('change', toggleCashSession);
    toggleCashSession();
</script>
@endsection
