<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticatedSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available_to_guests(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('MICRO')->assertSee('Se connecter')->assertSee('name="_token"', false);
    }

    public function test_guest_is_redirected_to_login_from_protected_routes(): void
    {
        foreach (['/dashboard', '/products', '/customers', '/quotes', '/invoices', '/payments'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_invalid_credentials_return_a_generic_error_without_authenticating(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);

        $this->assertGuest();
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['is_active' => false]);

        $this->from(route('login'))->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'Votre compte est désactivé. Contactez l’administrateur.']);

        $this->assertGuest();
    }

    public function test_an_inactive_user_session_is_terminated_on_the_next_request(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['is_active' => false]);

        $this->actingAs($user)->get(route('quotes.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_successful_login_hashes_password_creates_session_and_redirects_to_dashboard(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['dashboard.view']);
        $sessionIdBeforeLogin = session()->getId();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
        $this->assertTrue(Hash::check('password', $user->password));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_authenticated_user_can_open_the_main_operator_modules(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($company, [
            'dashboard.view', 'customer.view', 'customer.create', 'customers.manage',
            'leads.manage', 'quotes.manage', 'invoice.view', 'invoice.create',
            'payment.view', 'payment.create', 'receipt.view', 'products.manage',
        ]));

        foreach (['/dashboard', '/products', '/customers', '/quotes', '/invoices', '/payments'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_logout_invalidates_the_session_and_protected_routes_redirect_again(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->for($company)->create());

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('quotes.index'))->assertRedirect(route('login'));
    }

    public function test_company_isolation_remains_active_after_login(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $customerB = Customer::factory()->for($companyB)->create(['legal_name' => 'Client confidentiel B']);
        $productB = Product::factory()->for($companyB)->create(['name' => 'Produit confidentiel B']);
        $quoteB = Quote::query()->create([
            'company_id' => $companyB->id,
            'customer_id' => $customerB->id,
            'number' => 'DEV-CONFIDENTIEL-B',
            'issue_date' => '2026-09-28',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 1000,
            'amount_paid' => 0,
            'balance_due' => 1000,
            'status' => 'draft',
        ]);
        $invoiceB = Invoice::query()->create([
            'company_id' => $companyB->id,
            'customer_id' => $customerB->id,
            'quote_id' => $quoteB->id,
            'number' => 'FAC-CONFIDENTIEL-B',
            'issue_date' => '2026-09-28',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 1000,
            'amount_paid' => 0,
            'balance_due' => 1000,
            'status' => 'draft',
        ]);
        $this->actingAs($this->createUserWithPermissions($companyA, [
            'customer.view', 'invoice.view', 'payment.view', 'payment.create', 'products.manage',
            'customers.manage', 'quotes.manage',
        ]));

        $this->get(route('products.index'))->assertOk()->assertDontSee($productB->name);
        $this->get(route('customers.index'))->assertOk()->assertDontSee('Client confidentiel B');
        $this->get(route('quotes.index'))->assertOk()->assertDontSee($quoteB->number);
        $this->get(route('invoices.index'))->assertOk()->assertDontSee($invoiceB->number);
        $this->get(route('products.show', $productB))->assertNotFound();
        $this->get(route('customers.show', $customerB))->assertNotFound();
        $this->get(route('quotes.show', $quoteB))->assertNotFound();
        $this->get(route('invoices.show', $invoiceB))->assertNotFound();
        $this->get(route('invoices.payments.index', $invoiceB))->assertNotFound();
    }

    public function test_dashboard_statistics_are_scoped_to_the_authenticated_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        Customer::factory()->for($companyB)->create(['status' => 'active']);
        Invoice::query()->create([
            'company_id' => $companyB->id,
            'customer_id' => Customer::query()->where('company_id', $companyB->id)->value('id'),
            'number' => 'FAC-OTHER-COMPANY',
            'issue_date' => '2026-09-28',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 1000,
            'amount_paid' => 0,
            'balance_due' => 1000,
            'status' => 'draft',
        ]);
        $this->actingAs($this->createUserWithPermissions($companyA, ['dashboard.view']));

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('<p>Clients actifs</p><strong>0</strong>', false)
            ->assertSee('<p>Factures émises</p><strong>0</strong>', false);
    }
}
