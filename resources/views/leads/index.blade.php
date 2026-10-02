@extends('layouts.app', ['heading' => 'Prospects', 'eyebrow' => 'CRM commercial'])
@section('content')
<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">Pipeline commercial</p>
        <h2 class="hero-title">Transformer les contacts en clients.</h2>
        <p class="muted-copy">Suivez chaque opportunité jusqu’à sa prochaine action.</p>
    </div><a href="{{ route('leads.create') }}" class="button-primary">+ Nouveau prospect</a>
</div>
<div class="grid gap-4 md:grid-cols-3">
    <div class="stat-card accent-green">
        <p>Nouveaux</p><strong>{{ $leads->where('stage', 'new')->count() }}</strong><small>À contacter</small>
    </div>
    <div class="stat-card accent-orange">
        <p>En négociation</p><strong>{{ $leads->where('stage', 'negotiation')->count() }}</strong><small>Opportunités actives</small>
    </div>
    <div class="stat-card accent-blue">
        <p>Potentiel</p><strong>{{ number_format($leads->sum('potential_amount'), 0, ',', ' ') }} <em>FCFA</em></strong><small>Pipeline visible</small>
    </div>
</div>
<section class="panel mt-8">
    <div class="panel-heading">
        <div>
            <p class="section-kicker">Opportunités</p>
            <h3>Prospects récents</h3>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Prospect</th>
                    <th>Étape</th>
                    <th>Potentiel</th>
                    <th>Prochaine action</th>
                </tr>
            </thead>
            <tbody>@forelse($leads as $lead)<tr>
                    <td><a class="font-semibold text-[#173b36]" href="{{ route('leads.show', $lead) }}">{{ $lead->name }}</a><small class="block text-[#71807d]">{{ $lead->email ?: $lead->phone ?: '—' }}</small></td>
                    <td><span class="status-pill status-neutral">{{ ucfirst($lead->stage) }}</span></td>
                    <td>{{ number_format($lead->potential_amount, 0, ',', ' ') }} FCFA</td>
                    <td>{{ $lead->next_action_at?->format('d/m/Y') ?: 'À planifier' }}</td>
                </tr>@empty<tr>
                    <td colspan="4">
                        <div class="empty-state"><span>◇</span>
                            <p>Aucun prospect</p><small>Ajoutez votre première opportunité commerciale.</small>
                        </div>
                    </td>
                </tr>@endforelse</tbody>
        </table>
    </div>
</section>
@endsection