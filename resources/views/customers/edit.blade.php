@extends('layouts.app', ['heading' => 'Modifier le client', 'eyebrow' => 'Relation client'])
@section('content')<div class="mb-8">
    <p class="section-kicker">{{ $customer->code }}</p>
    <h2 class="hero-title">Modifier {{ $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name) }}.</h2>
</div>
<form class="panel form-grid" method="post" action="{{ route('customers.update', $customer) }}">@csrf @method('PUT')<div class="form-fields"><label>Type<select name="type">
                <option value="individual" @selected($customer->type === 'individual')>Particulier</option>
                <option value="company" @selected($customer->type === 'company')>Entreprise</option>
                <option value="institution" @selected($customer->type === 'institution')>Institution</option>
            </select></label><label>Raison sociale<input name="legal_name" value="{{ $customer->legal_name }}"></label><label>Nom<input name="last_name" value="{{ $customer->last_name }}"></label><label>Prénom<input name="first_name" value="{{ $customer->first_name }}"></label><label>Email<input type="email" name="email" value="{{ $customer->email }}"></label><label>Téléphone<input name="phone" value="{{ $customer->phone }}"></label><label>Ville<input name="city" value="{{ $customer->city }}"></label></div>
    <div class="form-actions"><a class="button-secondary" href="{{ route('customers.show', $customer) }}">Annuler</a><button class="button-primary">Enregistrer</button></div>
</form>@endsection