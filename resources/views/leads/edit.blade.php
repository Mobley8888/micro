@extends('layouts.app', ['heading' => 'Modifier le prospect', 'eyebrow' => 'CRM commercial'])
@section('content')<div class="mb-8">
    <p class="section-kicker">{{ $lead->id }}</p>
    <h2 class="hero-title">{{ $lead->name }}</h2>
</div>
<section class="panel">
    <p class="muted-copy">Le formulaire d’édition du pipeline sera activé avec les étapes de conversion prospect → client.</p>
</section>@endsection