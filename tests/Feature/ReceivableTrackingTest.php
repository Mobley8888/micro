<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Receivable;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceivableTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_conversion_creates_one_receivable_and_synchronization_is_idempotent(): void
    {
        [$company, $customer, $invoice, $method] = $this->makeInvoice();
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 2500))->assertRedirect();
        $receivable = Receivable::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame($company->id, $receivable->invoice->company_id);
        $this->assertSame($customer->id, $receivable->customer_id);
        $this->assertEquals(100000, (float) $receivable->amount_due);
        $this->assertEquals(2500, (float) $receivable->amount_paid);
        $this->assertEquals(97500, (float) $receivable->balance_due);
        $this->assertSame('partially_paid', $receivable->status);
        $this->assertSame('2026-10-15', $receivable->due_date->toDateString());

        app(ReceivableService::class)->synchronize($invoice->fresh());
        $this->assertSame(1, Receivable::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_full_payment_closes_receivable_without_changing_commercial_invoice_status(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 100000))->assertRedirect();
        $invoice->refresh();
        $receivable = Receivable::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame('sent', $invoice->status);
        $this->assertSame('paid', $invoice->financial_status);
        $this->assertEquals(0, (float) $invoice->balance_due);
        $this->assertEquals(0, (float) $receivable->balance_due);
        $this->assertSame('paid', $receivable->status);
    }

    public function test_payment_creates_unique_receipt_number_and_keeps_operator_and_balance_snapshot(): void
    {
        [, , $invoice, $method, $operator] = $this->makeInvoice();
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 30000))->assertRedirect();
        $first = Payment::query()->firstOrFail();
        $this->assertSame('REC-2026-000001', $first->receipt_number);
        $this->assertSame($operator->id, $first->received_by);
        $this->assertEquals(70000, (float) $first->balance_after);
        $this->get(route('payments.receipt', $first))->assertOk()
            ->assertSee($first->receipt_number)->assertSee($invoice->number)
            ->assertSee('Mode test')->assertSee($operator->name)->assertSee('window.print()');
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 20000))->assertRedirect();
        $second = Payment::query()->whereKeyNot($first->id)->firstOrFail();
        $this->assertSame('REC-2026-000002', $second->receipt_number);
        $this->assertEquals(50000, (float) $second->balance_after);
        $this->assertSame(2, Payment::query()->where('receipt_number', $first->receipt_number)->count() + Payment::query()->where('receipt_number', $second->receipt_number)->count());
    }

    public function test_receivables_journal_filters_overdue_and_is_company_scoped(): void
    {
        [$company, , $invoice] = $this->makeInvoice(dueDate: '2026-09-01');
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        app(ReceivableService::class)->synchronize($invoice);
        $otherCompany = Company::factory()->create();
        $otherCustomer = Customer::factory()->for($otherCompany)->create();
        $otherInvoice = $this->createInvoice($otherCompany, $otherCustomer, 'FAC-FOREIGN', '2026-08-01', 900000);
        app(ReceivableService::class)->synchronize($otherInvoice);
        $this->actingAs($this->createUserWithPermissions($company, ['invoice.view']));

        $this->get(route('receivables.index'))->assertOk()->assertSee($invoice->number)->assertDontSee($otherInvoice->number);
        $this->get(route('receivables.index', ['status' => 'overdue']))->assertOk()->assertSee($invoice->number)->assertSee('30 jour(s)')->assertDontSee($otherInvoice->number);
    }

    public function test_customer_financial_summary_and_dashboard_use_company_scoped_posted_data(): void
    {
        [$company, $customer, $invoice, $method] = $this->makeInvoice(dueDate: '2026-09-01');
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 25000))->assertRedirect();
        $otherCompany = Company::factory()->create();
        $otherCustomer = Customer::factory()->for($otherCompany)->create();
        $otherInvoice = $this->createInvoice($otherCompany, $otherCustomer, 'FAC-OTHER', '2026-08-01', 900000);
        app(ReceivableService::class)->synchronize($otherInvoice);
        $this->actingAs($this->createUserWithPermissions($company, [
            'customer.view', 'invoice.view', 'payment.view', 'dashboard.view',
        ]));

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('100 000')->assertSee('25 000')->assertSee('75 000');
        $this->get(route('dashboard'))->assertOk()->assertSee('100 000')->assertSee('75 000')->assertDontSee('900 000');
    }

    public function test_super_admin_can_view_global_financial_statistics_and_receivables(): void
    {
        [$company, , $invoice] = $this->makeInvoice();
        app(ReceivableService::class)->synchronize($invoice);
        $otherCompany = Company::factory()->create();
        $otherCustomer = Customer::factory()->for($otherCompany)->create();
        $otherInvoice = $this->createInvoice($otherCompany, $otherCustomer, 'FAC-GLOBAL', '2026-10-15', 900000);
        app(ReceivableService::class)->synchronize($otherInvoice);
        $superAdmin = User::factory()->for($company)->create();
        $role = Role::query()->create(['company_id' => null, 'name' => 'Super Administrateur', 'slug' => 'super-administrator']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['slug' => 'invoice.view'], ['name' => 'Consulter les factures']));
        $superAdmin->roles()->attach($role);
        $this->actingAs($superAdmin);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('1 000 000');
        $this->get(route('receivables.index'))->assertOk()->assertSee($invoice->number)->assertSee($otherInvoice->number);
    }

    public function test_paid_invoice_is_not_overdue_and_days_late_are_calculated(): void
    {
        [, , $invoice, $method] = $this->makeInvoice(dueDate: '2026-09-30');
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        app(ReceivableService::class)->synchronize($invoice);
        $this->assertSame(1, $invoice->fresh()->daysOverdue());
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 100000))->assertRedirect();
        $this->assertSame(0, $invoice->fresh()->daysOverdue());
        $this->get(route('receivables.index', ['status' => 'overdue']))->assertOk()->assertDontSee($invoice->number);
    }

    public function test_anonymous_and_foreign_company_users_cannot_open_receipts_or_receivables(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 1000))->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        auth()->logout();
        $this->get(route('receivables.index'))->assertRedirect(route('login'));
        $this->get(route('payments.receipt', $payment))->assertRedirect(route('login'));
        $otherCompany = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($otherCompany, ['receipt.view']));
        $this->get(route('payments.receipt', $payment))->assertNotFound();
        $this->assertDatabaseCount('receivables', 1);
    }

    private function makeInvoice(?string $dueDate = '2026-10-15'): array
    {
        $company = Company::factory()->create();
        $operator = $this->createUserWithPermissions($company, [
            'customer.view', 'invoice.view', 'payment.view', 'payment.create', 'receipt.view',
        ]);
        $this->actingAs($operator);
        $customer = Customer::factory()->for($company)->create();
        $method = PaymentMethod::query()->create(['company_id' => $company->id, 'name' => 'Mode test', 'code' => 'TEST', 'is_active' => true]);
        $invoice = $this->createInvoice($company, $customer, 'FAC-'.fake()->unique()->numerify('######'), $dueDate, 100000);

        return [$company, $customer, $invoice, $method, $operator];
    }

    private function createInvoice(Company $company, Customer $customer, string $number, ?string $dueDate, int $total): Invoice
    {
        return Invoice::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'number' => $number,
            'issue_date' => '2026-09-01',
            'due_date' => $dueDate,
            'subtotal' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'status' => 'sent',
        ]);
    }

    private function payload(PaymentMethod $method, int $amount): array
    {
        return ['amount' => $amount, 'payment_date' => '2026-09-28', 'payment_method_id' => $method->id, 'reference' => 'REF-PHASE6'];
    }
}
