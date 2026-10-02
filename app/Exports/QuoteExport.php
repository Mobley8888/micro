<?php

namespace App\Exports;

use App\Models\Quote;
use Maatwebsite\Excel\Concerns\FromArray;

class QuoteExport implements FromArray
{
    public function __construct(private readonly Quote $quote) {}

    public function array(): array
    {
        $company = $this->quote->company;
        $customer = $this->quote->customer;
        $customerName = $customer->legal_name ?: trim($customer->first_name.' '.$customer->last_name);
        $rows = [
            ['Entreprise', $company->legal_name ?: $company->name],
            ['Numéro du devis', $this->quote->number],
            ['Date', $this->quote->issue_date?->format('d/m/Y')],
            ['Échéance', $this->quote->due_date?->format('d/m/Y')],
            ['Client', $customerName],
            ['Statut', $this->quote->status],
            [],
            ['Code', 'Désignation', 'Quantité', 'Prix unitaire', 'Remise', 'Taux TVA', 'Montant TVA', 'Sous-total HT', 'Total TTC'],
        ];

        foreach ($this->quote->items as $item) {
            $rows[] = [
                $item->product?->code,
                $item->description,
                (float) $item->quantity,
                (float) $item->unit_price,
                (float) $item->discount_amount,
                (float) $item->tax_rate,
                (float) $item->tax_amount,
                max(0, (float) $item->quantity * (float) $item->unit_price - (float) $item->discount_amount),
                (float) $item->total,
            ];
        }

        $rows[] = [];
        $rows[] = ['Sous-total HT', (float) $this->quote->subtotal];
        $rows[] = ['Remise', (float) $this->quote->discount_amount];
        $rows[] = ['TVA', (float) $this->quote->tax_amount];
        $rows[] = ['Total TTC', (float) $this->quote->total];

        return $rows;
    }
}
