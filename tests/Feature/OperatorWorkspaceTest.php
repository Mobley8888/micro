<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_search_is_partial_case_insensitive_limited_and_company_scoped(): void
    {
        [$user, $company] = $this->operator(['customer.view']);
        Customer::factory()->for($company)->create(['legal_name' => 'ETS MABIALA SASSO SERVICES']);
        Customer::factory()->for($company)->create(['first_name' => 'Jean', 'last_name' => 'SASSOU']);
        $otherCompany = Company::factory()->create();
        Customer::factory()->for($otherCompany)->create(['legal_name' => 'SASSO FOREIGN']);

        foreach (['sasso', 'SASSO', 'SaSsO'] as $term) {
            $response = $this->actingAs($user)->getJson(route('customers.search', ['q' => $term]))->assertOk();
            $response->assertJsonCount(2, 'data')->assertJsonMissing(['name' => 'SASSO FOREIGN']);
        }

        $this->getJson(route('customers.search', ['q' => '  ']))->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_customer_search_returns_at_most_ten_results_and_requires_permission(): void
    {
        [$user, $company] = $this->operator(['customer.view']);
        Customer::factory()->count(12)->for($company)->create(['legal_name' => 'SASSO SERVICES']);
        $this->actingAs($user)->getJson(route('customers.search', ['q' => 'sasso']))->assertOk()->assertJsonCount(10, 'data');

        [$unauthorized] = $this->operator([]);
        $this->actingAs($unauthorized)->getJson(route('customers.search', ['q' => 'sasso']))->assertForbidden();
        auth()->logout();
        $this->getJson(route('customers.search', ['q' => 'sasso']))->assertUnauthorized();
    }

    public function test_quick_customer_creation_is_tenant_scoped_and_requires_customer_create(): void
    {
        [$user, $company] = $this->operator(['customer.create']);
        $response = $this->actingAs($user)->postJson(route('customers.quick-store'), [
            'type' => 'individual', 'first_name' => 'Jean', 'last_name' => 'Sasso', 'phone' => '+242060000000',
        ])->assertCreated()->assertJsonPath('data.name', 'Jean Sasso');
        $this->assertSame($company->id, Customer::query()->findOrFail($response->json('data.id'))->company_id);

        [$unauthorized] = $this->operator([]);
        $this->actingAs($unauthorized)->postJson(route('customers.quick-store'), [
            'type' => 'company', 'legal_name' => 'Refusée',
        ])->assertForbidden();
    }

    public function test_operator_can_open_and_view_only_their_cash_session(): void
    {
        [$user, $company] = $this->operator(['cash.view', 'cash.open']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user)->post(route('cash.my-session.open'), [
            'cash_register_id' => $register->id, 'opening_amount' => '50000.00',
        ])->assertRedirect(route('cash.my-session'));
        $this->get(route('cash.my-session'))->assertOk()->assertSee('50 000,00')->assertSee($register->name);
        $this->assertSame($user->id, CashSession::query()->firstOrFail()->opened_by);

        [$otherUser, $otherCompany] = $this->operator(['cash.view']);
        $this->actingAs($otherUser)->get(route('cash.my-session'))->assertOk()->assertSee('Aucune session de caisse ouverte');
        $this->assertNotSame($company->id, $otherCompany->id);
    }

    public function test_invoice_creation_uses_company_product_prices_and_updates_receivable(): void
    {
        [$user, $company] = $this->operator(['invoice.create']);
        $customer = Customer::factory()->for($company)->create();
        $tax = Tax::query()->create(['company_id' => $company->id, 'name' => 'TVA test', 'rate' => '18.00', 'is_active' => true]);
        $product = Product::factory()->for($company)->create(['tax_id' => $tax->id, 'sale_price' => '1000.00', 'name' => 'Installation réseau']);

        $this->actingAs($user)->get(route('invoices.create'))->assertOk()->assertSee('Rechercher un client')->assertSee('Rechercher un produit');
        $this->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'issue_date' => '2026-09-30',
            'due_date' => '2026-10-30',
            'lines' => [['product_id' => $product->id, 'quantity' => '2', 'discount_amount' => '100.00']],
        ])->assertRedirect();

        $invoice = Invoice::query()->with('items', 'receivable')->firstOrFail();
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('2000.00', $invoice->subtotal);
        $this->assertSame('100.00', $invoice->discount_amount);
        $this->assertSame('342.00', $invoice->tax_amount);
        $this->assertSame('2242.00', $invoice->total);
        $this->assertSame('2242.00', $invoice->receivable->balance_due);
        $this->assertSame($company->id, $invoice->company_id);
    }

    public function test_invoice_creation_rejects_foreign_customer_and_product(): void
    {
        [$user, $company] = $this->operator(['invoice.create']);
        $otherCompany = Company::factory()->create();
        $customer = Customer::factory()->for($otherCompany)->create();
        $product = Product::factory()->for($company)->create();

        $this->actingAs($user)->from(route('invoices.create'))->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'issue_date' => '2026-09-30',
            'lines' => [['product_id' => $product->id, 'quantity' => '1']],
        ])->assertRedirect(route('invoices.create'))->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_invoice_creation_requires_permission(): void
    {
        [$user, $company] = $this->operator([]);
        $this->actingAs($user)->get(route('invoices.create'))->assertForbidden();
        $this->assertSame(0, $company->invoices()->count());
    }

    public function test_invoice_with_cash_payment_uses_personal_session_and_creates_receipt_and_movement(): void
    {
        [$user, $company] = $this->operator(['invoice.create', 'payment.create', 'cash.open', 'cash.move', 'cash.view']);
        $customer = Customer::factory()->for($company)->create();
        $product = Product::factory()->for($company)->create(['sale_price' => '2500.00']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user)->post(route('cash.my-session.open'), ['cash_register_id' => $register->id, 'opening_amount' => '0'])->assertRedirect();
        $session = CashSession::query()->firstOrFail();
        $cash = PaymentMethod::query()->create(['company_id' => $company->id, 'name' => 'Espèces', 'code' => 'cash', 'is_active' => true]);

        $this->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'issue_date' => '2026-09-30',
            'lines' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method_id' => $cash->id,
        ])->assertRedirect();

        $invoice = Invoice::query()->firstOrFail();
        $payment = Payment::query()->firstOrFail();
        $this->assertSame($session->id, $payment->cash_session_id);
        $this->assertNotNull($payment->receipt_number);
        $this->assertSame('paid', $invoice->fresh()->financial_status);
        $this->assertSame(2, CashMovement::query()->count());
        $this->assertSame($payment->id, CashMovement::query()->where('type', 'payment')->value('source_id'));
        $this->assertDatabaseHas('receivables', ['invoice_id' => $invoice->id, 'balance_due' => '0.00']);
    }

    public function test_invoice_cash_payment_without_session_rolls_back_and_bank_payment_needs_no_cash(): void
    {
        [$user, $company] = $this->operator(['invoice.create', 'payment.create', 'cash.move']);
        $customer = Customer::factory()->for($company)->create();
        $product = Product::factory()->for($company)->create(['sale_price' => '1000.00']);
        $cash = PaymentMethod::query()->create(['company_id' => $company->id, 'name' => 'Espèces', 'code' => 'cash', 'is_active' => true]);
        $bank = PaymentMethod::query()->create(['company_id' => $company->id, 'name' => 'Virement', 'code' => 'bank', 'is_active' => true]);
        $payload = ['customer_id' => $customer->id, 'issue_date' => '2026-09-30', 'lines' => [['product_id' => $product->id, 'quantity' => '1']]];

        $this->actingAs($user)->from(route('invoices.create'))->post(route('invoices.store'), [...$payload, 'payment_method_id' => $cash->id])
            ->assertRedirect(route('invoices.create'))->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);

        $this->post(route('invoices.store'), [...$payload, 'payment_method_id' => $bank->id])
            ->assertRedirect();
        $this->assertNull(Payment::query()->firstOrFail()->cash_session_id);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_opening_cash_from_invoice_draft_preserves_invoice_payload(): void
    {
        [$user, $company] = $this->operator(['cash.open', 'cash.view', 'invoice.create']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $draft = ['customer_id' => 'client-id', 'issue_date' => '2026-09-30', 'lines' => [['product_id' => 'article-id', 'quantity' => '3']]];
        $this->actingAs($user)->post(route('cash.my-session.prepare'), $draft)->assertRedirect(route('cash.my-session', ['return_to' => 'invoice']));
        $this->post(route('cash.my-session.open'), ['cash_register_id' => $register->id, 'opening_amount' => '0', 'return_to' => 'invoice'])
            ->assertRedirect(route('invoices.create'));
        $this->get(route('invoices.create'))->assertOk()->assertSee('article-id', false);
    }

    private function operator(array $permissionSlugs): array
    {
        $company = Company::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Opérateur', 'slug' => 'operator']);
        $permissionIds = collect($permissionSlugs)->map(fn (string $slug): int => Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug])->id);
        $role->permissions()->sync($permissionIds);
        $user = User::factory()->for($company)->create();
        $user->roles()->attach($role);

        return [$user, $company];
    }
}
