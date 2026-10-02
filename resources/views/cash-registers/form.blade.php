@extends('layouts.app', ['heading' => $cashRegister->exists ? 'Modifier une caisse' : 'Créer une caisse', 'eyebrow' => 'Trésorerie'])
@section('content')
<div class="mb-8"><p class="section-kicker">Configuration</p><h2 class="hero-title">{{ $cashRegister->exists ? 'Modifier la caisse.' : 'Créer une caisse.' }}</h2><p class="muted-copy">Les mouvements historiques restent conservés.</p></div>
@if($errors->any())<div class="flash-error mb-5" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="panel form-grid" method="post" action="{{ $cashRegister->exists ? route('cash-registers.update', $cashRegister) : route('cash-registers.store') }}">@csrf @if($cashRegister->exists)@method('put')@endif
<div class="form-section"><p class="section-kicker">Identification</p><h3>Informations de la caisse</h3><div class="form-fields">
@if(!$cashRegister->exists && auth()->user()->isSuperAdmin())<label>Entreprise<select name="company_id" required><option value="">Choisir une entreprise</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id') === $company->id)>{{ $company->legal_name ?: $company->name }}</option>@endforeach</select>@error('company_id')<span role="alert" class="text-red-700">{{ $message }}</span>@enderror</label>@endif
<label>Nom<input name="name" value="{{ old('name', $cashRegister->name) }}" required maxlength="255">@error('name')<span role="alert" class="text-red-700">{{ $message }}</span>@enderror</label>
<label>Code<input name="code" value="{{ old('code', $cashRegister->code) }}" required maxlength="50" pattern="[A-Za-z0-9_-]+">@error('code')<span role="alert" class="text-red-700">{{ $message }}</span>@enderror</label>
<label>Description<textarea class="search-input" name="description" rows="3" maxlength="2000">{{ old('description', $cashRegister->description) }}</textarea></label>
@if($cashRegister->exists)<label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $cashRegister->is_active))> Caisse active</label>@endif
</div></div><div class="form-actions"><a class="button-secondary" href="{{ $cashRegister->exists ? route('cash-registers.show', $cashRegister) : route('cash-registers.index') }}">Annuler</a><button class="button-primary">Enregistrer</button></div>
</form>
@endsection

