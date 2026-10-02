<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\Commercial\InvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_users_cannot_access_or_convert_invoices(): void
    {
        [$company, $customer, $quote] = $this->makeQuote('accepted');
        auth()->logout();

        $this->get(route('invoices.index'))->assertRedirect(route('login'));
        $this->post(route('quotes.convert-to-invoice', $quote))->assertRedirect(route('login'));
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNotNull($company->id);
        $this->assertNotNull($customer->id);
    }

    public function test_accepted_quote_is_converted_to_a_draft_invoice_with_copied_snapshots(): void
    {
        [$company, $customer, $quote] = $this->makeQuote('accepted');

        $this->post(route('quotes.convert-to-invoice', $quote))->assertRedirect();
        $invoice = Invoice::query()->where('quote_id', $quote->id)->with('items')->firstOrFail();

        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame($quote->id, $invoice->quote_id);
        $this->assertSame('draft', $invoice->status);
        $this->assertEquals(0, (float) $invoice->amount_paid);
        $this->assertEquals((float) $quote->total, (float) $invoice->balance_due);
        $this->assertEquals((float) $quote->subtotal, (float) $invoice->subtotal);
        $this->assertEquals((float) $quote->discount_amount, (float) $invoice->discount_amount);
        $this->assertEquals((float) $quote->tax_amount, (float) $invoice->tax_amount);
        $this->assertEquals((float) $quote->total, (float) $invoice->total);
        $this->assertSame('FAC-2026-000001', $invoice->number);
        $this->assertSame(1, $invoice->items->count());
        $this->assertSame('Ligne capturée', $invoice->items->first()->description);
        $this->assertSame('2026-09-25', $invoice->issue_date->toDateString());
        $this->assertSame('unpaid', $invoice->financial_status);
        $this->assertSame(1, $invoice->receivable()->count());
        $this->assertEquals((float) $invoice->total, (float) $invoice->receivable->balance_due);
    }

    public function test_non_accepted_quotes_cannot_be_converted(): void
    {
        foreach (['draft', 'sent', 'rejected', 'expired', 'cancelled'] as $status) {
            [$company, , $quote] = $this->makeQuote($status);
            $this->actingAs($this->createUserWithPermissions($company, ['quotes.manage', 'invoice.create']));
            $this->post(route('quotes.convert-to-invoice', $quote))->assertSessionHasErrors('quote');
            $this->assertDatabaseMissing('invoices', ['quote_id' => $quote->id]);
        }
    }

    public function test_quote_cannot_be_converted_twice_and_model_relations_are_defined(): void
    {
        [, , $quote] = $this->makeQuote('accepted');
        $this->post(route('quotes.convert-to-invoice', $quote))->assertRedirect();
        $this->post(route('quotes.convert-to-invoice', $quote))->assertSessionHasErrors('quote');

        $invoice = Invoice::query()->where('quote_id', $quote->id)->firstOrFail();
        $this->assertSame($invoice->id, $quote->fresh()->invoice->id);
        $this->assertSame($quote->id, $invoice->quote->id);
        $this->assertSame(1, Invoice::query()->where('quote_id', $quote->id)->count());
    }

    public function test_user_cannot_convert_another_companys_quote(): void
    {
        [$company, , $quote] = $this->makeQuote('accepted');
        $otherCompany = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($otherCompany, [
            'quotes.manage', 'invoice.create', 'invoice.view',
        ]));

        $this->post(route('quotes.convert-to-invoice', $quote))->assertNotFound();
        $this->assertDatabaseMissing('invoices', ['quote_id' => $quote->id]);
        $this->assertNotSame($company->id, $otherCompany->id);
    }

    public function test_invoice_pages_display_persisted_invoice_data(): void
    {
        [$company, , $quote] = $this->makeQuote('accepted');
        app(InvoiceService::class)->convertFromQuote($quote);
        $invoice = Invoice::query()->where('quote_id', $quote->id)->firstOrFail();

        $this->get(route('invoices.index'))->assertOk()->assertSee($invoice->number);
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Devis '.$quote->number)
            ->assertSee('Solde restant')
            ->assertSee('Imprimer')
            ->assertSee('window.print()')
            ->assertSee('@media print', false)
            ->assertSee($company->legal_name ?: $company->name);
    }

    public function test_failed_line_copy_rolls_back_the_invoice_and_numbering_change(): void
    {
        [, , $quote] = $this->makeQuote('accepted');
        InvoiceItem::creating(static function (): void {
            throw new \RuntimeException('Échec simulé de copie de ligne.');
        });

        try {
            app(InvoiceService::class)->convertFromQuote($quote);
            $this->fail('La conversion aurait dû échouer.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Échec simulé de copie de ligne.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('invoices', ['quote_id' => $quote->id]);
        $this->assertDatabaseMissing('numbering_settings', ['company_id' => $quote->company_id, 'document_type' => 'FAC']);
        $this->assertSame('accepted', $quote->fresh()->status);
    }

    public function test_database_unique_constraint_rejects_a_second_invoice_for_the_same_quote(): void
    {
        [, , $quote] = $this->makeQuote('accepted');
        $invoice = app(InvoiceService::class)->convertFromQuote($quote);

        try {
            Invoice::create([
                'company_id' => $invoice->company_id,
                'customer_id' => $invoice->customer_id,
                'quote_id' => $quote->id,
                'number' => 'FAC-DUPLICATE',
                'issue_date' => $invoice->issue_date,
                'subtotal' => $invoice->subtotal,
                'discount_amount' => $invoice->discount_amount,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'amount_paid' => 0,
                'balance_due' => $invoice->balance_due,
                'status' => 'draft',
            ]);
            $this->fail('La contrainte unique aurait dû refuser cette seconde facture.');
        } catch (QueryException) {
            $this->assertSame(1, Invoice::query()->where('quote_id', $quote->id)->count());
        }
    }

    public function test_company_cannot_view_another_companys_invoice_or_quote(): void
    {
        [, , $quote] = $this->makeQuote('accepted');
        $invoice = app(InvoiceService::class)->convertFromQuote($quote);
        $otherCompany = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($otherCompany, [
            'quotes.manage', 'invoice.create', 'invoice.view',
        ]));

        $this->get(route('quotes.show', $quote))->assertNotFound();
        $this->get(route('invoices.show', $invoice))->assertNotFound();
        $this->get(route('invoices.index'))->assertDontSee($invoice->number);
    }

    private function makeQuote(string $status): array
    {
        $company = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($company, [
            'quotes.manage', 'invoice.create', 'invoice.view',
        ]));
        $customer = Customer::factory()->for($company)->create();
        $product = Product::factory()->for($company)->create(['code' => 'PRD-001']);
        $quote = Quote::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'number' => 'DEV-'.$company->id,
            'issue_date' => '2026-09-25',
            'due_date' => '2026-10-25',
            'subtotal' => 10000,
            'discount_amount' => 500,
            'tax_amount' => 1710,
            'total' => 11210,
            'amount_paid' => 0,
            'balance_due' => 11210,
            'status' => $status,
            'notes' => 'Conditions de test',
        ]);
        QuoteItem::create([
            'quote_id' => $quote->id,
            'product_id' => $product->id,
            'description' => 'Ligne capturée',
            'quantity' => 1,
            'unit_price' => 10000,
            'discount_amount' => 500,
            'tax_rate' => 18,
            'tax_amount' => 1710,
            'total' => 11210,
        ]);

        return [$company, $customer, $quote];
    }
}
