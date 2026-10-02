@extends('layouts.app', ['heading' => 'Paramètres', 'eyebrow' => 'Administration'])
@section('content')<div class="mb-8">
    <p class="section-kicker">Configuration</p>
    <h2 class="hero-title">MICRO s’adapte à votre entreprise.</h2>
    <p class="muted-copy">Identité, taxes, moyens de paiement et numérotation seront configurables ici.</p>
</div>
<section class="panel form-grid">
    <div class="form-section">
        <h3>Entreprise</h3>
        <div class="form-fields"><label>Nom de l’entreprise<input value="HESTAM ÉDUCATION"></label><label>Devise<input value="XAF / FCFA"></label><label>Email<input value="hestameducations@gmail.com"></label><label>Téléphone<input value="+242066141000"></label></div>
    </div>
    <div class="form-actions"><button class="button-primary">Enregistrer les paramètres</button></div>
</section>@endsection