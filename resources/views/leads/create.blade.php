@extends('layouts.app', ['heading' => 'Nouveau prospect', 'eyebrow' => 'CRM commercial'])
@section('content')<div class="mb-8">
    <p class="section-kicker">Pipeline commercial</p>
    <h2 class="hero-title">Ajouter une opportunité.</h2>
    <p class="muted-copy">Un prospect bien documenté est une relation qui commence bien.</p>
</div>
<form class="panel form-grid" method="post" action="{{ route('leads.store') }}">@csrf<div class="form-fields"><label>Nom du prospect<input name="name" value="{{ old('name') }}" required></label><label>Email<input type="email" name="email" value="{{ old('email') }}"></label><label>Téléphone<input name="phone" value="{{ old('phone') }}"></label><label>Étape<select name="stage">
                <option value="new">Nouveau</option>
                <option value="contacted">Contacté</option>
                <option value="qualification">Qualification</option>
                <option value="proposal">Proposition</option>
                <option value="negotiation">Négociation</option>
            </select></label><label>Montant potentiel<input type="number" min="0" step="0.01" name="potential_amount" value="{{ old('potential_amount', 0) }}"></label><label>Prochaine action<input type="date" name="next_action_at" value="{{ old('next_action_at') }}"></label></div><label class="form-fields">Notes<textarea class="search-input" name="notes" rows="4">{{ old('notes') }}</textarea></label>
    <div class="form-actions"><a class="button-secondary" href="{{ route('leads.index') }}">Annuler</a><button class="button-primary">Enregistrer le prospect</button></div>
</form>@endsection