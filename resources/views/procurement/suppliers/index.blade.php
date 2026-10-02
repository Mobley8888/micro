@extends('layouts.app', ['heading' => 'Fournisseurs', 'eyebrow' => 'Achats'])
@section('content')
    <div class="mb-8">
        <p class="section-kicker">Achats</p>
        <h2 class="hero-title">Fournisseurs</h2>
        <p class="muted-copy">Les réceptions confirmées alimentent le stock par le moteur central.</p>
    </div>

    @if(auth()->user()->hasPermission('suppliers.create'))
        <section class="panel mb-8">
            <h3 class="mb-5 text-xl font-semibold">Nouveau fournisseur</h3>
            <form method="POST" action="{{ route('suppliers.store') }}" class="grid gap-4 md:grid-cols-2">
                @csrf
                <div><label for="code" class="mb-2 block font-semibold">Code</label><input id="code" name="code" value="{{ old('code') }}" required maxlength="100" class="form-input w-full">@error('code')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="name" class="mb-2 block font-semibold">Nom</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="255" class="form-input w-full">@error('name')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div><label for="currency" class="mb-2 block font-semibold">Devise</label><select id="currency" name="currency" required class="form-input w-full"><option value="XAF" @selected(old('currency', 'XAF') === 'XAF')>XAF</option><option value="EUR" @selected(old('currency') === 'EUR')>EUR</option><option value="USD" @selected(old('currency') === 'USD')>USD</option></select></div>
                <div><label for="phone" class="mb-2 block font-semibold">Téléphone</label><input id="phone" name="phone" value="{{ old('phone') }}" maxlength="100" class="form-input w-full"></div>
                <div><label for="email" class="mb-2 block font-semibold">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" class="form-input w-full">@error('email')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div class="md:col-span-2"><button type="submit" class="button-primary">Créer le fournisseur</button></div>
            </form>
        </section>
    @endif

    <section class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Code</th><th>Fournisseur</th><th>Contact</th><th>Commandes</th></tr></thead>
                <tbody>
                    @forelse($suppliers as $supplier)
                        <tr><td>{{ $supplier->code }}</td><td class="font-semibold">{{ $supplier->name }}</td><td>{{ $supplier->phone ?: $supplier->email ?: '—' }}</td><td>{{ $supplier->purchase_orders_count }}</td></tr>
                    @empty
                        <tr><td colspan="4"><div class="empty-state"><span>♧</span><p>Aucun fournisseur enregistré.</p></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-6">{{ $suppliers->links() }}</div>
    </section>
@endsection
