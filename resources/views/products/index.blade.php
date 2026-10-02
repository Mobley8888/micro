@extends('layouts.app', ['heading' => 'Produits & services', 'eyebrow' => 'Catalogue'])
@section('content')
<div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
    <div>
        <p class="section-kicker">Catalogue</p>
        <h2 class="hero-title">Une offre facile à facturer.</h2>
        <p class="muted-copy">Gérez vos produits, services, prix et taxes au même endroit.</p>
    </div><a class="button-primary" href="{{ route('products.create') }}">+ Nouveau</a>
</div>
@if ($errors->any())<div class="flash-error">{{ $errors->first() }}</div>@endif
<section class="panel">
    <form class="toolbar" method="get" action="{{ route('products.index') }}"><input class="search-input" name="search" value="{{ request('search') }}" placeholder="Rechercher par code ou nom…"><select class="search-input" name="type">
            <option value="">Tous les types</option>
            <option value="product" @selected(request('type')==='product' )>Produit</option>
            <option value="service" @selected(request('type')==='service' )>Service</option>
        </select><select class="search-input" name="status">
            <option value="">Tous les statuts</option>
            <option value="1" @selected(request('status')==='1' )>Actif</option>
            <option value="0" @selected(request('status')==='0' )>Inactif</option>
        </select><button class="button-secondary" type="submit">Filtrer</button></form>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Nom</th>
                    <th>Type</th>
                    <th>Prix HT</th>
                    <th>Stock</th>
                    <th>État</th>
                    <th>TVA</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>@forelse ($products as $product)<tr>
                    <td class="font-semibold">{{ $product->code }}</td>
                    <td><a class="text-link" href="{{ route('products.show', $product) }}">{{ $product->name }}</a><small class="block text-[#71807d]">{{ Str::limit($product->description, 50) }}</small></td>
                    <td>{{ $product->type === 'product' ? 'Produit' : 'Service' }}</td>
                    <td>{{ number_format($product->sale_price, 0, ',', ' ') }} FCFA</td>
                    <td>@if($product->is_stockable === false)
                            <span class="status-pill status-neutral">Non stockable</span>
                        @elseif($product->currentStockQuantity() === null)
                            <span class="status-pill status-neutral">Stock non initialisé</span>
                        @else
                            <span class="font-semibold">{{ $product->stockDisplayLabel() }}</span>
                        @endif</td>
                    <td>@if($product->is_stockable === false)
                            <span class="status-pill status-neutral">NON STOCKABLE</span>
                        @elseif($product->currentStockQuantity() === null)
                            <span class="status-pill status-neutral">NON INITIALISÉ</span>
                        @else
                            @php($state = $product->stockState())
                            @switch($state)
                                @case('NORMAL')<span class="status-pill status-green">NORMAL</span>@break
                                @case('RÉAPPROVISIONNEMENT')<span class="status-pill status-warning">RÉAPPROVISIONNEMENT</span>@break
                                @case('RUPTURE')<span class="status-pill status-red">RUPTURE</span>@break
                                @case('À SURVEILLER')<span class="status-pill status-warning">À SURVEILLER</span>@break
                                @default<span class="status-pill status-neutral">{{ $state }}</span>@endswitch
                        @endif</td>
                    <td>{{ $product->tax ? number_format($product->tax->rate, 2, ',', ' ').' %' : 'Exonéré' }}</td>
                    <td><span class="status-pill {{ $product->is_active ? 'status-green' : 'status-neutral' }}">{{ $product->is_active ? 'Actif' : 'Inactif' }}</span></td>
                    <td>
                        <div class="flex flex-wrap gap-2"><a class="text-link" href="{{ route('products.edit', $product) }}">Modifier</a>
                            <form method="post" action="{{ route($product->is_active ? 'products.deactivate' : 'products.activate', $product) }}" onsubmit="return confirm('Confirmer le changement de statut ?')">@csrf @method('PATCH')<button class="text-link" type="submit">{{ $product->is_active ? 'Désactiver' : 'Activer' }}</button></form>
                            <form method="post" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm('Archiver ce produit ou service ?')">@csrf @method('DELETE')<button class="text-link text-[#a55349]" type="submit">Archiver</button></form>
                        </div>
                    </td>
                </tr>@empty<tr>
                    <td colspan="9">
                        <div class="empty-state"><span>□</span>
                            <p>Aucun produit ou service trouvé</p><small>Créez votre première offre commerciale.</small>
                        </div>
                    </td>
                </tr>@endforelse</tbody>
        </table>
    </div>{{ $products->links() }}
</section>
@endsection