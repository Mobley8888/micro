@extends('layouts.app', ['heading' => 'Nouveau devis', 'eyebrow' => 'Relation client'])
@section('content')
<div class="mb-8">
    <p class="section-kicker">Ventes</p>
    <h2 class="hero-title">Préparer une proposition.</h2>
    <p class="muted-copy">Les montants définitifs sont recalculés côté serveur à l’enregistrement.</p>
</div>
@if ($errors->any())<div class="flash-error">{{ $errors->first() }}</div>@endif
<form class="panel form-grid" method="post" action="{{ route('quotes.store') }}" id="quote-form">@csrf
    <div class="form-fields"><label>Client<select name="customer_id" required>
                <option value="">Sélectionner un client</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id')==$customer->id)>{{ $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name) }} — {{ $customer->code }}</option>@endforeach
            </select></label><label>Date du devis<input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" required></label><label>Échéance<input type="date" name="due_date" value="{{ old('due_date') }}"></label></div>
    <div class="form-section">
        <div class="panel-heading">
            <div>
                <p class="section-kicker">Détail</p>
                <h3>Lignes du devis</h3>
            </div><button class="button-secondary" type="button" id="add-line">+ Ajouter une ligne</button>
        </div>
        <div id="quote-lines"></div>
        <div class="quote-total-box"><span>Total HT <b id="subtotal">0 FCFA</b></span><span>Remise <b id="discount">0 FCFA</b></span><span>TVA <b id="tax">0 FCFA</b></span><strong>Total TTC <b id="total">0 FCFA</b></strong></div>
    </div>
    <label class="form-fields">Notes<textarea class="search-input" name="notes" rows="4">{{ old('notes') }}</textarea></label>
    <div class="form-actions"><a class="button-secondary" href="{{ route('quotes.index') }}">Annuler</a><button class="button-primary" type="submit">Enregistrer le devis</button></div>
</form>
<script>
    const quoteProducts = @json($productOptions);
    const lines = document.getElementById('quote-lines');

    function addLine(line = {}) {
        const index = lines.children.length;
        const row = document.createElement('div');
        row.className = 'quote-line';
        row.innerHTML = `<select name="lines[${index}][product_id]" class="product-select"><option value="">Ligne libre</option>${quoteProducts.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}</select><input name="lines[${index}][description]" placeholder="Description" required value="${line.description || ''}"><input name="lines[${index}][quantity]" type="number" min="0.001" step="0.001" placeholder="Qté" required value="${line.quantity || 1}"><input name="lines[${index}][unit_price]" type="number" min="0" step="0.01" placeholder="Prix HT" required value="${line.unit_price || 0}"><input name="lines[${index}][discount_amount]" type="number" min="0" step="0.01" placeholder="Remise" value="${line.discount_amount || 0}"><input name="lines[${index}][tax_rate]" type="number" min="0" max="100" step="0.01" placeholder="TVA %" value="${line.tax_rate || 0}"><button class="text-link text-[#a55349]" type="button">Retirer</button>`;
        lines.appendChild(row);
        const select = row.querySelector('.product-select');
        select.value = line.product_id || '';
        select.addEventListener('change', () => {
            const p = quoteProducts.find(item => item.id === select.value);
            if (p) {
                row.querySelector('[name$="[description]"]').value = p.description || p.name;
                row.querySelector('[name$="[unit_price]"]').value = p.price;
                row.querySelector('[name$="[tax_rate]"]').value = p.tax;
            }
            calculate();
        });
        row.querySelector('button').addEventListener('click', () => {
            row.remove();
            calculate();
        });
        row.querySelectorAll('input').forEach(input => input.addEventListener('input', calculate));
    }

    function calculate() {
        let subtotal = 0,
            discount = 0,
            tax = 0;
        document.querySelectorAll('.quote-line').forEach(row => {
            const values = [...row.querySelectorAll('input')].map(input => Number(input.value) || 0);
            subtotal += values[0] * values[1];
            discount += values[2];
            tax += Math.max(0, values[0] * values[1] - values[2]) * values[3] / 100;
        });
        document.getElementById('subtotal').textContent = subtotal.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('discount').textContent = discount.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('tax').textContent = tax.toLocaleString('fr-FR') + ' FCFA';
        document.getElementById('total').textContent = (subtotal - discount + tax).toLocaleString('fr-FR') + ' FCFA';
    }
    document.getElementById('add-line').addEventListener('click', () => addLine());
    addLine();
</script>
@endsection