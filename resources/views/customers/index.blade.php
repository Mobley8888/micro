@extends('layouts.app', ['heading' => 'Clients', 'eyebrow' => 'Relation client'])
@section('content')
<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">Base clientèle</p>
        <h2 class="hero-title">Tous vos clients.</h2>
        <p class="muted-copy">Recherchez, suivez et enrichissez chaque relation.</p>
    </div>@if(auth()->user()->hasPermission('customer.create'))<a href="{{ route('customers.create') }}" class="button-primary">+ Nouveau client</a>@endif
</div>
<section class="panel">
    <div class="toolbar"><input class="search-input" placeholder="Rechercher par nom, code ou email…"><button class="button-secondary">Filtrer</button></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Code</th>
                    <th>Contact</th>
                    <th>Statut</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>@forelse($customers as $customer)<tr>
                    <td><a class="font-semibold text-[#173b36]" href="{{ route('customers.show', $customer) }}">{{ $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name) }}</a></td>
                    <td>{{ $customer->code }}</td>
                    <td>{{ $customer->email ?: $customer->phone ?: '—' }}</td>
                    <td><span class="status-pill status-green">Actif</span></td>
                    <td>@if(auth()->user()->hasPermission('customers.manage'))<a class="text-link" href="{{ route('customers.edit', $customer) }}">Modifier</a>@endif</td>
                </tr>@empty<tr>
                    <td colspan="5">
                        <div class="empty-state"><span>◉</span>
                            <p>Aucun client pour le moment</p><small>Commencez par créer votre premier client.</small>
                        </div>
                    </td>
                </tr>@endforelse</tbody>
        </table>
    </div>
</section>
@endsection