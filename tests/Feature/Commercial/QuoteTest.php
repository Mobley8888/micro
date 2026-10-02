<?php

namespace Tests\Feature\Commercial;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_users_cannot_access_quotes(): void
    {
        $this->get(route('quotes.index'))->assertRedirect(route('login'));
    }

    public function test_quote_list_is_displayed(): void
    {
        $quote = $this->createQuote();

        $this->get(route('quotes.index'))->assertOk()->assertSee($quote->number);
    }

    public function test_quote_with_multiple_lines_is_persisted_and_calculated(): void
    {
        [$company, $customer, $product] = $this->commercialData();
        $this->actingAs($this->createUserWithPermissions($company, ['quotes.manage', 'invoice.create', 'invoice.view']));
        $secondProduct = Product::factory()->for($company)->create(['code' => 'SRV-002', 'sale_price' => 20000]);

        $response = $this->post(route('quotes.store'), $this->quotePayload($customer, [
            ['product_id' => $product->id, 'description' => 'Première ligne', 'quantity' => 2, 'unit_price' => 10000, 'discount_amount' => 1000, 'tax_rate' => 18],
            ['product_id' => $secondProduct->id, 'description' => 'Deuxième ligne', 'quantity' => 1, 'unit_price' => 20000, 'discount_amount' => 0, 'tax_rate' => 0],
        ]));

        $response->assertRedirect();
        $quote = Quote::query()->latest('created_at')->firstOrFail();
        $this->assertSame('DEV-2026-000001', $quote->number);
        $this->assertEquals(40000, (float) $quote->subtotal);
        $this->assertEquals(1000, (float) $quote->discount_amount);
        $this->assertEquals(3420, (float) $quote->tax_amount);
        $this->assertEquals(42420, (float) $quote->total);
        $this->assertSame(2, $quote->items()->count());
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        [$company, $customer, $product] = $this->commercialData();
        $this->actingAs($this->createUserWithPermissions($company, ['quotes.manage']));

        $this->from(route('quotes.create'))->post(route('quotes.store'), $this->quotePayload($customer, [[
            'product_id' => $product->id,
            'description' => 'Ligne invalide',
            'quantity' => 0,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertRedirect(route('quotes.create'))->assertSessionHasErrors('lines.0.quantity');
    }

    public function test_quote_can_be_updated_and_viewed(): void
    {
        $quote = $this->createQuote();
        [, $customer, $product] = $this->commercialData($quote->company_id);

        $this->put(route('quotes.update', $quote), $this->quotePayload($customer, [[
            'product_id' => $product->id,
            'description' => 'Ligne modifiée',
            'quantity' => 3,
            'unit_price' => 10000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertRedirect(route('quotes.show', $quote));
        $this->get(route('quotes.show', $quote))->assertOk()->assertSee('Ligne modifiée');
        $this->assertEquals(30000, (float) $quote->refresh()->total);
    }

    public function test_quote_status_transitions_are_controlled(): void
    {
        $quote = $this->createQuote();
        [, $customer, $product] = $this->commercialData($quote->company_id);

        $this->patch(route('quotes.send', $quote))->assertRedirect();
        $this->assertSame('sent', $quote->refresh()->status);
        $this->patch(route('quotes.accept', $quote))->assertRedirect();
        $this->assertSame('accepted', $quote->refresh()->status);
        $this->put(route('quotes.update', $quote), $this->quotePayload($customer, [[
            'product_id' => $product->id,
            'description' => 'Modification interdite',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertRedirect()->assertSessionHasErrors('quote');
    }

    public function test_customer_and_product_must_belong_to_the_same_company(): void
    {
        [$company, $customer] = $this->commercialData();
        $this->actingAs($this->createUserWithPermissions($company, ['quotes.manage']));
        [$otherCompany, $otherCustomer, $otherProduct] = $this->commercialData();
        $this->assertNotSame($company->id, $otherCompany->id);

        $this->from(route('quotes.create'))->post(route('quotes.store'), $this->quotePayload($customer, [[
            'product_id' => $otherProduct->id,
            'description' => 'Produit externe',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertRedirect(route('quotes.create'))->assertSessionHasErrors('lines.0.product_id');

        $this->from(route('quotes.create'))->post(route('quotes.store'), $this->quotePayload($otherCustomer, [[
            'product_id' => null,
            'description' => 'Client externe',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertRedirect(route('quotes.create'))->assertSessionHasErrors('customer_id');
    }

    public function test_quote_excel_export_downloads_a_real_workbook_without_changing_the_quote(): void
    {
        $quote = $this->createQuote();
        $before = $quote->only(['status', 'subtotal', 'discount_amount', 'tax_amount', 'total']);

        $response = $this->get(route('quotes.export.excel', $quote));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->streamedContent());
        $this->assertSame($before, $quote->fresh()->only(['status', 'subtotal', 'discount_amount', 'tax_amount', 'total']));
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_quote_print_view_contains_print_action_and_document_values_without_side_effects(): void
    {
        $quote = $this->createQuote();
        $response = $this->get(route('quotes.show', $quote));

        $response->assertOk()->assertSee('Imprimer')->assertSee('Exporter Excel')->assertSee('window.print()')
            ->assertSee('@media print', false)->assertSee($quote->number)->assertSee('Ligne test');
        $this->assertSame('draft', $quote->fresh()->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_user_cannot_export_another_companys_quote(): void
    {
        $quote = $this->createQuote();
        $otherCompany = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($otherCompany, ['quotes.manage']));

        $this->get(route('quotes.export.excel', $quote))->assertNotFound();
    }

    public function test_company_cannot_view_edit_change_or_export_another_companys_quote(): void
    {
        $quote = $this->createQuote();
        $otherCompany = Company::factory()->create();
        $user = $this->createUserWithPermissions($otherCompany, ['quotes.manage']);
        $this->actingAs($user);

        $this->get(route('quotes.show', $quote))->assertNotFound();
        $this->get(route('quotes.edit', $quote))->assertNotFound();
        $this->get(route('quotes.export.excel', $quote))->assertNotFound();
        $this->patch(route('quotes.accept', $quote))->assertNotFound();
        $this->delete(route('quotes.destroy', $quote))->assertNotFound();
        $otherCustomer = Customer::factory()->for($otherCompany)->create();
        $otherProduct = Product::factory()->for($otherCompany)->create();
        $this->put(route('quotes.update', $quote), $this->quotePayload($otherCustomer, [[
            'product_id' => $otherProduct->id,
            'description' => 'Modification étrangère',
            'quantity' => 2,
            'unit_price' => 5000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertNotFound();

        $this->assertSame('draft', $quote->fresh()->status);
        $this->assertDatabaseCount('quote_items', 1);
    }

    public function test_user_without_quote_management_permission_cannot_view_or_create_quotes(): void
    {
        [$company, $customer, $product] = $this->commercialData();
        $operator = $this->createUserWithPermissions($company, ['invoice.create']);
        $this->actingAs($operator);

        $this->get(route('quotes.index'))->assertForbidden();
        $this->post(route('quotes.store'), $this->quotePayload($customer, [[
            'product_id' => $product->id,
            'description' => 'Devis interdit',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]]))->assertForbidden();

        $this->assertDatabaseCount('quotes', 0);
    }

    private function createQuote(?string $companyId = null): Quote
    {
        [$company, $customer, $product] = $this->commercialData($companyId);
        $this->actingAs($this->createUserWithPermissions($company, ['quotes.manage', 'invoice.create', 'invoice.view']));

        $this->post(route('quotes.store'), $this->quotePayload($customer, [[
            'product_id' => $product->id,
            'description' => 'Ligne test',
            'quantity' => 1,
            'unit_price' => 10000,
            'discount_amount' => 0,
            'tax_rate' => 18,
        ]]));

        return Quote::query()->where('company_id', $company->id)->latest('created_at')->firstOrFail();
    }

    private function commercialData(?string $existingCompanyId = null): array
    {
        $company = $existingCompanyId ? Company::findOrFail($existingCompanyId) : Company::factory()->create();
        $customer = Customer::factory()->for($company)->create();
        $product = Product::factory()->for($company)->create(['tax_id' => null]);
        Tax::firstOrCreate(['company_id' => $company->id, 'name' => 'TVA test'], ['rate' => 18]);

        return [$company, $customer, $product];
    }

    private function quotePayload(Customer $customer, array $lines): array
    {
        return ['customer_id' => $customer->id, 'issue_date' => '2026-09-25', 'due_date' => '2026-10-25', 'notes' => 'Test', 'lines' => $lines];
    }
}
