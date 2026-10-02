@extends('layouts.app', ['heading' => 'Modifier le devis', 'eyebrow' => 'Relation client'])
@section('content')
<div class="mb-8">
    <p class="section-kicker">{{ $quote->number }}</p>
    <h2 class="hero-title">Modifier la proposition.</h2>
    <p class="muted-copy">Un devis accepté ou envoyé ne peut plus être modifié.</p>
</div>
@if ($errors->any())<div class="flash-error">{{ $errors->first() }}</div>@endif
<form class="panel form-grid" method="post" action="{{ route('quotes.update', $quote) }}" id="quote-form">@csrf @method('PUT')<div class="form-fields"><label>Client<select name="customer_id" required>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected($quote->customer_id === $customer->id)>{{ $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name) }} — {{ $customer->code }}</option>@endforeach</select></label><label>Date du devis<input type="date" name="issue_date" value="{{ $quote->issue_date->toDateString() }}" required></label><label>Échéance<input type="date" name="due_date" value="{{ $quote->due_date?->toDateString() }}"></label></div>
    <div class="form-section">
        <div class="panel-heading">
            <div>
                <p class="section-kicker">Détail</p>
                <h3>Lignes du devis</h3>
            </div><button class="button-secondary" type="button" id="add-line">+ Ajouter une ligne</button>
        </div>
        <div id="quote-lines"></div>
        <div class="quote-total-box"><span>Total HT <b id="subtotal">0 FCFA</b></span><span>Remise <b id="discount">0 FCFA</b></span><span>TVA <b id="tax">0 FCFA</b></span><strong>Total TTC <b id="total">0 FCFA</b></strong></div>
    </div><label class="form-fields">Notes<textarea class="search-input" name="notes" rows="4">{{ $quote->notes }}</textarea></label>
    <div class="form-actions"><a class="button-secondary" href="{{ route('quotes.show', $quote) }}">Annuler</a><button class="button-primary">Enregistrer</button></div>
</form>
<script>
    const quoteProducts = @json($productOptions);
    const existingLines = @json($quote - > items);
    const lines = document.getElementById('quote-lines');

    function addLine(line = {}) {
        const index = lines.children.length;
        const row = document.createElement('div');
        row.className = 'quote-line';
        row.innerHTML = `<select name="lines[${index}][product_id]" class="product-select"><option value="">Ligne libre</option>${quoteProducts.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}</select><input name="lines[${index}][description]" placeholder="Description" required value="${line.description || ''}"><input name="lines[${index}][quantity]" type="number" min="0.001" step="0.001" required value="${line.quantity || 1}"><input name="lines[${index}][unit_price]" type="number" min="0" step="0.01" required value="${line.unit_price || 0}"><input name="lines[${index}][discount_amount]" type="number" min="0" step="0.01" value="${line.discount_amount || 0}"><input name="lines[${index}][tax_rate]" type="number" min="0" max="100" step="0.01" value="${line.tax_rate || 0}"><button class="text-link text-[#a55349]" type="button">Retirer</button>`;
        lines.appendChild(row);
        row.querySelector('.product-select').value = line.product_id || '';
        row.querySelector('.product-select').addEventListener('change', event => {
            const p = quoteProducts.find(item => item.id === event.target.value);
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
    existingLines.forEach(addLine);
    if (!existingLines.length) addLine();
    calculate();
</script>
@endsection