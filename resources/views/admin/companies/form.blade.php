@extends('layouts.app', ['heading' => $company->exists ? 'Modifier une entreprise' : 'Créer une entreprise', 'eyebrow' => 'Administration'])
@section('content')
<div class="panel max-w-4xl">
<form method="post" action="{{ $company->exists ? route('admin.companies.update', $company) : route('admin.companies.store') }}" class="grid gap-5 md:grid-cols-2">
@csrf @if ($company->exists) @method('put') @endif
@foreach (['name' => 'Nom commercial', 'legal_name' => 'Raison sociale', 'email' => 'Email', 'phone' => 'Téléphone', 'tax_number' => 'Identifiant fiscal', 'registration_number' => 'RCCM', 'country' => 'Pays', 'city' => 'Ville', 'currency_code' => 'Devise (3 lettres)'] as $field => $label)
<label class="grid gap-1 text-sm font-semibold">{{ $label }}<input class="rounded-lg border p-3" name="{{ $field }}" value="{{ old($field, $company->$field ?? ($field === 'country' ? 'Congo' : ($field === 'currency_code' ? 'XAF' : ''))) }}" @if (in_array($field, ['name','country','currency_code'])) required @endif>@error($field)<span class="text-red-700">{{ $message }}</span>@enderror</label>
@endforeach
<div class="flex gap-3 md:col-span-2"><button class="button-primary">Enregistrer</button><a class="button-secondary" href="{{ route('admin.companies.index') }}">Annuler</a></div>
</form>
</div>
@endsection
