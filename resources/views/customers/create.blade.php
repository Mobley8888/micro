@extends('layouts.app', ['heading' => 'Nouveau client', 'eyebrow' => 'Relation client'])
@section('content')<div class="mb-8">
    <p class="section-kicker">Base clientèle</p>
    <h2 class="hero-title">Créer un client.</h2>
    <p class="muted-copy">Centralisez les informations essentielles dès le premier contact.</p>
</div>
<form class="panel form-grid" method="post" action="{{ route('customers.store') }}">@csrf<div class="form-section">
        <h3>Identité</h3>
        <div class="form-fields"><label>Type<select name="type">
                    <option value="individual">Particulier</option>
                    <option value="company">Entreprise</option>
                    <option value="institution">Institution</option>
                </select></label><label>Raison sociale<input name="legal_name" placeholder="Nom de l’organisation"></label><label>Nom<input name="last_name" placeholder="Nom"></label><label>Prénom<input name="first_name" placeholder="Prénom"></label></div>
    </div>
    <div class="form-section">
        <h3>Coordonnées</h3>
        <div class="form-fields"><label>Email<input type="email" name="email" placeholder="client@exemple.com"></label><label>Téléphone<input name="phone" placeholder="+242 …"></label><label>Ville<input name="city" placeholder="Brazzaville"></label><label>Adresse<input name="address" placeholder="Adresse complète"></label></div>
    </div>
    <div class="form-actions"><a class="button-secondary" href="{{ route('customers.index') }}">Annuler</a><button class="button-primary" type="submit">Enregistrer le client</button></div>
</form>@endsection