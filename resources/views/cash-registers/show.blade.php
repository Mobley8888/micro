@extends('layouts.app', ['heading' => $cashRegister->name, 'eyebrow' => 'Trésorerie · '.$cashRegister->company->name])
@section('content')
<div class="mb-8 flex flex-wrap items-end justify-between gap-4"><div><p class="section-kicker">{{ $cashRegister->code }} · {{ $cashRegister->is_active ? 'Active' : 'Inactive' }}</p><h2 class="hero-title">{{ $cashRegister->name }}.</h2><p class="muted-copy">{{ $cashRegister->description ?: 'Caisse de '.$cashRegister->company->name }}</p></div><div class="flex flex-wrap gap-2">@if(auth()->user()->hasPermission('cash.create'))<a class="button-secondary" href="{{ route('cash-registers.edit', $cashRegister) }}">Modifier</a><form method="post" action="{{ route($cashRegister->is_active ? 'cash-registers.deactivate' : 'cash-registers.activate', $cashRegister) }}">@csrf @method('patch')<button class="button-secondary">{{ $cashRegister->is_active ? 'Désactiver' : 'Activer' }}</button></form>@endif<a class="button-secondary" href="{{ route('cash-registers.index') }}">Retour aux caisses</a></div></div>
@if($errors->any())<div class="flash-error mb-5" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(!$openSession)
@if($cashRegister->is_active && auth()->user()->hasPermission('cash.open'))
<form class="panel form-grid mb-8" method="post" action="{{ route('cash-registers.sessions.open', $cashRegister) }}">@csrf<div><p class="section-kicker">Nouvelle session</p><h3>Ouvrir la caisse</h3></div><div class="form-fields"><label>Fonds d’ouverture (FCFA)<input class="search-input" type="number" name="opening_amount" min="0" step="0.01" value="{{ old('opening_amount', '0.00') }}" required>@error('opening_amount')<span role="alert" class="text-red-700">{{ $message }}</span>@enderror</label></div><div class="form-actions"><button class="button-primary">Ouvrir la session</button></div></form>
@elseif(!$cashRegister->is_active)<p class="panel mb-8">Cette caisse est inactive et ne peut pas être ouverte.</p>@endif
@else
<section class="panel mb-8"><div class="panel-heading"><div><p class="section-kicker">Session ouverte · {{ $openSession->opened_at->format('d/m/Y H:i') }}</p><h3>Situation de caisse</h3><p class="muted-copy">Ouverte par {{ $openSession->openedBy->name }}</p></div><span class="status-pill status-green">En activité</span></div>
<div class="stats-grid"><div class="stat-card"><p>Fonds d’ouverture</p><strong>{{ number_format((float) $snapshot['opening_amount'], 2, ',', ' ') }} <em>FCFA</em></strong></div><div class="stat-card accent-green"><p>Total entrées</p><strong>{{ number_format((float) $snapshot['total_in'], 2, ',', ' ') }} <em>FCFA</em></strong></div><div class="stat-card accent-red"><p>Total sorties</p><strong>{{ number_format((float) $snapshot['total_out'], 2, ',', ' ') }} <em>FCFA</em></strong></div><div class="stat-card accent-blue"><p>Solde théorique</p><strong>{{ number_format((float) $snapshot['expected_amount'], 2, ',', ' ') }} <em>FCFA</em></strong></div></div>
<p class="muted-copy mt-4">{{ $snapshot['movement_count'] }} mouvement(s) · Dernier : {{ $snapshot['last_movement']?->occurred_at->format('d/m/Y H:i') ?? '—' }}</p></section>
<div class="grid gap-6 xl:grid-cols-2 mb-8">
@if(auth()->user()->hasPermission('cash.move'))
<form id="cash-movement-form" class="panel form-grid" method="post" enctype="multipart/form-data" action="{{ route('cash-registers.sessions.movements.store', [$cashRegister, $openSession]) }}">
    @csrf
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
    <div><p class="section-kicker">Mouvement manuel</p><h3>Entrée ou sortie</h3><p class="muted-copy">Enregistrez un mouvement daté dans cette session de caisse.</p></div>
    <div class="form-fields">
        <label>Opération<select class="search-input" name="type" required><option value="deposit" @selected(old('type') === 'deposit')>Entrée de caisse</option><option value="withdrawal" @selected(old('type', 'withdrawal') === 'withdrawal')>Sortie de caisse</option></select>@error('type')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label>Montant (FCFA)<input class="search-input" type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0 FCFA" value="{{ old('amount') }}" required>@error('amount')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label>Date<input class="search-input" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}">@error('occurred_at')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label>Bénéficiaire<input class="search-input" name="beneficiary" maxlength="255" value="{{ old('beneficiary') }}" placeholder="Nom du bénéficiaire">@error('beneficiary')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label>Référence<input class="search-input" name="reference" value="{{ old('reference') }}" maxlength="255" placeholder="Référence du mouvement">@error('reference')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label class="md:col-span-2">Motif / justification<textarea class="search-input" name="description" rows="3" maxlength="2000" required>{{ old('description') }}</textarea>@error('description')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label class="md:col-span-2">Justificatif<input id="cash-movement-attachment" class="search-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><span class="text-xs font-normal text-slate-500">PDF, JPEG ou PNG — 5 Mo maximum. <span id="cash-movement-attachment-name">Aucun fichier choisi.</span></span>@error('attachment')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label class="md:col-span-2">Notes<textarea class="search-input" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>@error('notes')<span role="alert" class="text-sm font-normal text-red-700">{{ $message }}</span>@enderror</label>
    </div>
    <div class="form-actions"><button id="cash-movement-submit" class="button-primary" type="submit">Enregistrer le mouvement</button></div>
</form>
@endif
@if(auth()->user()->hasPermission('cash.close'))<form class="panel form-grid" method="post" action="{{ route('cash-registers.sessions.close', [$cashRegister, $openSession]) }}">@csrf<div><p class="section-kicker">Fin de session</p><h3>Clôturer la caisse</h3><p class="muted-copy">Solde théorique : {{ number_format((float) $snapshot['expected_amount'], 2, ',', ' ') }} FCFA</p></div><div class="form-fields"><label>Montant réellement compté (FCFA)<input class="search-input" type="number" name="closing_amount" min="0" step="0.01" value="{{ old('closing_amount', $snapshot['expected_amount']) }}" required>@error('closing_amount')<span role="alert" class="text-red-700">{{ $message }}</span>@enderror</label><label>Note d’écart<input class="search-input" name="closing_note" value="{{ old('closing_note') }}" maxlength="2000" placeholder="Obligatoire en cas d’écart">@error('closing_note')<span role="alert" class="text-red-700">{{ $message }}</span>@enderror</label></div><div class="form-actions"><button class="button-primary">Clôturer la session</button></div></form>@endif
</div>
<section class="panel mb-8">
    <div class="panel-heading">
        <div>
            <p class="section-kicker">Mouvements</p>
            <h3>Historique de la session</h3>
        </div>
    </div>
    @if($movements->isEmpty())
        <div class="empty-state"><p>Aucun mouvement</p></div>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Date</th><th>Type</th><th>Sens</th><th>Description</th><th>Référence</th><th>Opérateur</th><th>Montant</th></tr>
                </thead>
                <tbody>
                    @foreach($movements as $movement)
                        <tr>
                            <td>{{ $movement->occurred_at->format('d/m/Y H:i') }}</td>
                            <td>{{ match($movement->type) {'opening' => 'Ouverture', 'payment' => 'Paiement espèces', 'deposit' => 'Entrée', 'withdrawal' => 'Sortie', default => ucfirst($movement->type)} }}</td>
                            <td>{{ $movement->direction === 'in' ? 'Entrée' : 'Sortie' }}</td>
                            <td>{{ $movement->description }}</td>
                            <td>
                                {{ $movement->reference ?: '—' }}
                                @if($movement->metadata['beneficiary'] ?? null)
                                    <span class="block text-xs text-slate-500">Bénéficiaire : {{ $movement->metadata['beneficiary'] }}</span>
                                @endif
                                @if($movement->metadata['attachment_path'] ?? null)
                                    <a class="block text-xs font-semibold text-[#28745d] underline" href="{{ route('cash-movements.attachment', $movement) }}">Voir le justificatif</a>
                                @endif
                            </td>
                            <td>{{ $movement->createdBy->name }}</td>
                            <td>{{ $movement->direction === 'out' ? '−' : '+' }}{{ number_format((float) $movement->amount, 2, ',', ' ') }} FCFA</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $movements->links() }}</div>
    @endif
</section>
@endif
<section class="panel"><div class="panel-heading"><div><p class="section-kicker">Historique</p><h3>Sessions de la caisse</h3></div></div>@if($sessions->isEmpty())<p class="muted-copy">Aucune session enregistrée.</p>@else<div class="table-wrap"><table class="data-table"><thead><tr><th>Ouverture</th><th>Ouverte par</th><th>Clôture</th><th>Statut</th><th>Théorique</th><th>Compté</th><th>Écart</th></tr></thead><tbody>@foreach($sessions as $session)<tr><td>{{ $session->opened_at->format('d/m/Y H:i') }}</td><td>{{ $session->openedBy->name }}</td><td>{{ $session->closed_at?->format('d/m/Y H:i') ?? '—' }}</td><td>{{ $session->status === 'open' ? 'Ouverte' : 'Clôturée' }}</td><td>{{ $session->expected_amount !== null ? number_format((float) $session->expected_amount, 2, ',', ' ').' FCFA' : '—' }}</td><td>{{ $session->closing_amount !== null ? number_format((float) $session->closing_amount, 2, ',', ' ').' FCFA' : '—' }}</td><td>{{ $session->difference !== null ? number_format((float) $session->difference, 2, ',', ' ').' FCFA' : '—' }}</td></tr>@endforeach</tbody></table></div><div class="mt-5">{{ $sessions->links() }}</div>@endif</section>
<script>
(() => {
    const form = document.getElementById('cash-movement-form');
    const attachment = document.getElementById('cash-movement-attachment');
    const fileName = document.getElementById('cash-movement-attachment-name');
    attachment?.addEventListener('change', () => { fileName.textContent = attachment.files?.[0]?.name || 'Aucun fichier choisi.'; });
    form?.addEventListener('submit', () => {
        const button = document.getElementById('cash-movement-submit');
        if (form.checkValidity() && button) { button.disabled = true; button.textContent = 'Enregistrement…'; }
    });
})();
</script>
@endsection
