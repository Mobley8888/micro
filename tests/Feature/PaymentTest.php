<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Commercial\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_partial_payment_updates_the_invoice_and_creates_payment_record(): void
    {
        [$company, $customer, $invoice, $method] = $this->makeInvoice();

        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 30000))
            ->assertRedirect(route('invoices.show', $invoice));

        $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame($company->id, $payment->company_id);
        $this->assertSame($customer->id, $payment->customer_id);
        $this->assertSame($method->id, $payment->payment_method_id);
        $this->assertSame('posted', $payment->status);
        $this->assertSame('ENC-2026-000001', $payment->number);
        $this->assertSame('2026-09-28', $payment->paid_at->toDateString());
        $this->assertSame('REF-001', $payment->reference);
        $this->assertEquals(30000, (float) $invoice->fresh()->amount_paid);
        $this->assertEquals(70000, (float) $invoice->fresh()->balance_due);
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('partially_paid', $invoice->fresh()->financial_status);
    }

    public function test_full_payment_marks_invoice_paid_with_zero_balance(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();

        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 100000))->assertRedirect();

        $this->assertEquals(100000, (float) $invoice->fresh()->amount_paid);
        $this->assertEquals(0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->financial_status);
    }

    public function test_payment_above_balance_is_rejected_without_changes(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();

        $this->from(route('invoices.payments.index', $invoice))
            ->post(route('invoices.payments.store', $invoice), $this->payload($method, 100000.01))
            ->assertRedirect(route('invoices.payments.index', $invoice))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(100000, (float) $invoice->fresh()->balance_due);
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();

        foreach ([0, -1, 'not-a-number'] as $amount) {
            $this->from(route('invoices.payments.index', $invoice))
                ->post(route('invoices.payments.store', $invoice), $this->payload($method, $amount))
                ->assertSessionHasErrors('amount');
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_cannot_be_recorded_after_invoice_is_paid(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();
        $invoice->update(['amount_paid' => 100000, 'balance_due' => 0, 'status' => 'paid']);

        $this->from(route('invoices.payments.index', $invoice))
            ->post(route('invoices.payments.store', $invoice), $this->payload($method, 1))
            ->assertRedirect(route('invoices.payments.index', $invoice))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_method_must_be_active_and_belong_to_the_company(): void
    {
        [, , $invoice] = $this->makeInvoice();
        $otherCompany = Company::factory()->create();
        $foreignMethod = PaymentMethod::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Mode étranger',
            'code' => 'FOREIGN',
            'is_active' => true,
        ]);
        $inactiveMethod = PaymentMethod::query()->create([
            'company_id' => $invoice->company_id,
            'name' => 'Mode inactif',
            'code' => 'INACTIVE',
            'is_active' => false,
        ]);

        foreach ([$foreignMethod, $inactiveMethod] as $method) {
            $this->from(route('invoices.payments.index', $invoice))
                ->post(route('invoices.payments.store', $invoice), $this->payload($method, 1000))
                ->assertSessionHasErrors('payment_method_id');
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_company_cannot_view_or_pay_another_companys_invoice(): void
    {
        [, , $invoice] = $this->makeInvoice();
        [$otherCompany, , , $otherMethod] = $this->makeInvoice();
        $this->actingAs($this->createUserWithPermissions($otherCompany, [
            'invoice.view', 'payment.view', 'payment.create',
        ]));

        $this->get(route('invoices.payments.index', $invoice))->assertNotFound();
        $this->post(route('invoices.payments.store', $invoice), $this->payload($otherMethod, 1000))->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_company_member_without_payment_create_permission_cannot_record_payment(): void
    {
        [$company, , $invoice, $method] = $this->makeInvoice();
        $this->actingAs($this->createUserWithPermissions($company, []));

        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 1000))
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('100000.00', $invoice->fresh()->balance_due);
    }

    public function test_payment_view_permission_can_view_history_without_recording_payments(): void
    {
        [$company, , $invoice, $method] = $this->makeInvoice();
        $this->actingAs($this->createUserWithPermissions($company, ['payment.view']));

        $this->get(route('invoices.payments.index', $invoice))
            ->assertOk()
            ->assertDontSee('Enregistrer le paiement');
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 1000))
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_journal_and_invoice_history_are_company_scoped(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 2500))->assertRedirect();
        $payment = Payment::query()->firstOrFail();
        $this->get(route('payments.index'))->assertOk()->assertSee($payment->number)->assertSee($invoice->number);
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Encaissements')->assertSee('2 500,00 FCFA');
        $this->get(route('invoices.payments.index', $invoice))->assertOk()->assertSee($payment->reference)->assertSee('Mode test');

        [$otherCompany] = $this->makeInvoice();
        $this->actingAs($this->createUserWithPermissions($otherCompany, [
            'invoice.view', 'payment.view', 'payment.create',
        ]));
        $this->get(route('payments.index'))->assertOk()->assertDontSee($payment->number);
        $this->get(route('invoices.payments.index', $invoice))->assertNotFound();
    }

    public function test_several_payments_sum_to_invoice_total_and_close_the_balance(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();

        foreach ([20000, 30000, 50000] as $amount) {
            $this->post(route('invoices.payments.store', $invoice), $this->payload($method, $amount))->assertRedirect();
        }

        $this->assertSame(3, $invoice->payments()->count());
        $this->assertEquals(100000, (float) $invoice->fresh()->amount_paid);
        $this->assertEquals(0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('draft', $invoice->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->financial_status);
        $this->get(route('invoices.payments.index', $invoice))->assertOk()->assertSee('Facture payée');
    }

    public function test_invoice_without_payments_is_presented_as_unpaid(): void
    {
        [, , $invoice] = $this->makeInvoice();

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Impayée')->assertSee('Enregistrer un paiement');
    }

    public function test_payment_method_page_does_not_offer_payment_when_no_balance_remains(): void
    {
        [, , $invoice] = $this->makeInvoice();
        $invoice->update(['amount_paid' => 100000, 'balance_due' => 0, 'status' => 'paid']);

        $this->get(route('invoices.payments.index', $invoice))->assertOk()->assertSee('Facture payée')->assertDontSee('Enregistrer le paiement');
    }

    public function test_anonymous_users_cannot_access_payment_pages_or_record_payments(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();
        auth()->logout();

        $this->get(route('payments.index'))->assertRedirect(route('login'));
        $this->get(route('invoices.payments.index', $invoice))->assertRedirect(route('login'));
        $this->post(route('invoices.payments.store', $invoice), $this->payload($method, 1000))->assertRedirect(route('login'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_failure_during_invoice_update_rolls_back_the_payment(): void
    {
        [, , $invoice, $method] = $this->makeInvoice();
        Invoice::updating(static function (): void {
            throw new \RuntimeException('Échec simulé de mise à jour.');
        });

        try {
            app(PaymentService::class)->recordPayment($invoice, $this->payload($method, 1000));
            $this->fail('La transaction aurait dû échouer.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Échec simulé de mise à jour.', $exception->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(0, (float) $invoice->fresh()->amount_paid);
        $this->assertEquals(100000, (float) $invoice->fresh()->balance_due);
    }

    private function makeInvoice(): array
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, [
            'customer.view', 'invoice.view', 'payment.view', 'payment.create', 'receipt.view',
        ]);
        $this->actingAs($user);
        $customer = Customer::factory()->for($company)->create();
        $paymentMethod = PaymentMethod::query()->create([
            'company_id' => $company->id,
            'name' => 'Mode test',
            'code' => 'TEST',
            'is_active' => true,
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'number' => 'FAC-'.$company->id,
            'issue_date' => '2026-09-25',
            'due_date' => '2026-10-25',
            'subtotal' => 100000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => 100000,
            'amount_paid' => 0,
            'balance_due' => 100000,
            'status' => 'draft',
        ]);

        return [$company, $customer, $invoice, $paymentMethod];
    }

    private function payload(PaymentMethod $paymentMethod, int|float|string $amount): array
    {
        return [
            'amount' => $amount,
            'payment_date' => '2026-09-28',
            'payment_method_id' => $paymentMethod->id,
            'reference' => 'REF-001',
            'notes' => 'Versement test',
        ];
    }
}
