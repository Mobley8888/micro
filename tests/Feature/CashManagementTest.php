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
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CashMovementService;
use App\Services\Commercial\CashSessionService;
use App\Services\Commercial\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CashManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_can_create_and_list_its_cash_registers_only(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.view', 'cash.create']);
        $otherCompany = Company::factory()->create();
        $otherRegister = CashRegister::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Caisse étrangère']);

        $this->post(route('cash-registers.store'), [
            'name' => 'Caisse principale',
            'code' => 'PRINCIPALE',
            'description' => 'Accueil',
        ])->assertRedirect();

        $register = CashRegister::query()->where('company_id', $company->id)->firstOrFail();
        $this->get(route('cash-registers.index'))->assertOk()
            ->assertSee('Caisse principale')
            ->assertDontSee('Caisse étrangère');
        $this->get(route('cash-registers.show', $otherRegister))->assertNotFound();
        $this->assertSame($user->company_id, $register->company_id);
    }

    public function test_cash_register_code_is_unique_per_company_not_globally(): void
    {
        [, $company] = $this->userWithPermissions(['cash.create']);
        $first = CashRegister::factory()->create(['company_id' => $company->id, 'code' => 'MAIN']);
        $this->from(route('cash-registers.create'))->post(route('cash-registers.store'), [
            'name' => 'Duplicate',
            'code' => 'MAIN',
        ])->assertRedirect(route('cash-registers.create'))->assertSessionHasErrors('code');

        $otherCompany = Company::factory()->create();
        $second = CashRegister::factory()->create(['company_id' => $otherCompany->id, 'code' => 'MAIN']);
        $this->assertSame('MAIN', $first->code);
        $this->assertSame('MAIN', $second->code);
    }

    public function test_register_management_requires_the_cash_permission(): void
    {
        $this->userWithPermissions([]);
        $this->get(route('cash-registers.index'))->assertForbidden();
    }

    public function test_cash_viewer_can_render_register_without_an_open_session(): void
    {
        $company = Company::factory()->create();
        $register = CashRegister::factory()->for($company)->create();
        $viewer = $this->createUserWithPermissions($company, ['cash.view']);

        $this->actingAs($viewer)->get(route('cash-registers.show', $register))
            ->assertOk()
            ->assertSee('Aucune session enregistrée.')
            ->assertDontSee('Ouvrir la session')
            ->assertDontSee('Modifier');

        $this->actingAs($viewer)->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '0'])
            ->assertForbidden();
        $this->assertDatabaseCount('cash_sessions', 0);
    }

    public function test_cash_viewer_can_render_open_session_but_cannot_open_move_or_close_it(): void
    {
        $company = Company::factory()->create();
        $register = CashRegister::factory()->for($company)->create();
        $operator = $this->createUserWithPermissions($company, ['cash.open', 'cash.move', 'cash.close']);
        $session = app(CashSessionService::class)->open($operator, $register, '500');
        $viewer = $this->createUserWithPermissions($company, ['cash.view']);

        $this->actingAs($viewer)->get(route('cash-registers.show', $register))
            ->assertOk()
            ->assertSee('Historique de la session')
            ->assertSee('Ouverture')
            ->assertDontSee('Enregistrer le mouvement')
            ->assertDontSee('Clôturer la session');

        $this->actingAs($viewer)->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '0'])
            ->assertForbidden();
        $this->actingAs($viewer)->post(route('cash-registers.sessions.movements.store', [$register, $session]), [
            'type' => 'deposit',
            'amount' => '10',
            'description' => 'Tentative non autorisée',
        ])->assertForbidden();
        $this->actingAs($viewer)->post(route('cash-registers.sessions.close', [$register, $session]), [
            'closing_amount' => '500',
        ])->assertForbidden();

        $this->assertSame(CashSession::STATUS_OPEN, $session->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_cash_viewer_can_render_closed_session_history(): void
    {
        $company = Company::factory()->create();
        $register = CashRegister::factory()->for($company)->create();
        $operator = $this->createUserWithPermissions($company, ['cash.open', 'cash.close']);
        $session = app(CashSessionService::class)->open($operator, $register, '500');
        app(CashSessionService::class)->close($operator, $session, '500', null);
        $viewer = $this->createUserWithPermissions($company, ['cash.view']);

        $this->actingAs($viewer)->get(route('cash-registers.show', $register))
            ->assertOk()
            ->assertSee('Sessions de la caisse')
            ->assertSee('Clôturée')
            ->assertDontSee('Historique de la session');
    }

    public function test_opening_creates_one_session_and_an_auditable_opening_movement(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.view', 'cash.open']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);

        $this->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '50000.25'])
            ->assertRedirect(route('cash-registers.show', $register));

        $session = CashSession::query()->firstOrFail();
        $movement = CashMovement::query()->firstOrFail();
        $this->assertSame(CashSession::STATUS_OPEN, $session->status);
        $this->assertSame($register->id, $session->cash_register_id);
        $this->assertSame($user->id, $session->opened_by);
        $this->assertSame('50000.25', $session->opening_amount);
        $this->assertSame(CashMovement::TYPE_OPENING, $movement->type);
        $this->assertSame(CashMovement::DIRECTION_IN, $movement->direction);
        $this->assertSame(CashSession::class, $movement->source_type);
        $this->assertSame($session->id, $movement->source_id);
    }

    public function test_a_register_cannot_have_two_open_sessions_and_invalid_opening_is_rejected(): void
    {
        [, $company] = $this->userWithPermissions(['cash.open']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);

        $this->from(route('cash-registers.show', $register))
            ->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '-1'])
            ->assertRedirect(route('cash-registers.show', $register))
            ->assertSessionHasErrors('opening_amount');
        $this->assertDatabaseCount('cash_sessions', 0);

        $this->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '0'])->assertRedirect();
        $this->from(route('cash-registers.show', $register))
            ->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '100'])
            ->assertRedirect(route('cash-registers.show', $register))
            ->assertSessionHasErrors('cash_register');
        $this->assertDatabaseCount('cash_sessions', 1);
    }

    public function test_deposits_withdrawals_and_close_reconcile_using_exact_decimal_arithmetic(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.view', 'cash.open', 'cash.move', 'cash.close']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $this->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '50000.25'])->assertRedirect();
        $session = CashSession::query()->firstOrFail();

        $this->post(route('cash-registers.sessions.movements.store', [$register, $session]), [
            'type' => 'deposit',
            'amount' => '2500.25',
            'description' => 'Fond ajouté',
        ])->assertRedirect();
        $this->post(route('cash-registers.sessions.movements.store', [$register, $session]), [
            'type' => 'withdrawal',
            'amount' => '1000.10',
            'description' => 'Retrait autorisé',
            'reference' => 'SORTIE-01',
        ])->assertRedirect();

        $service = app(CashSessionService::class);
        $snapshot = $service->snapshot($session);
        $this->assertSame('2500.25', $snapshot['total_in']);
        $this->assertSame('1000.10', $snapshot['total_out']);
        $this->assertSame('51500.40', $snapshot['expected_amount']);
        $this->assertSame(3, $snapshot['movement_count']);
        $this->assertSame($user->id, CashMovement::query()->where('type', 'deposit')->value('created_by'));

        $this->post(route('cash-registers.sessions.close', [$register, $session]), [
            'closing_amount' => '51501.00',
            'closing_note' => 'Écart compté',
        ])->assertRedirect(route('cash-registers.show', $register));

        $session->refresh();
        $this->assertSame(CashSession::STATUS_CLOSED, $session->status);
        $this->assertSame($user->id, $session->closed_by);
        $this->assertSame('51500.40', $session->expected_amount);
        $this->assertSame('51501.00', $session->closing_amount);
        $this->assertSame('0.60', $session->difference);
    }

    public function test_closing_requires_a_note_for_a_difference_and_a_closed_session_is_immutable(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.move', 'cash.close']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $this->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '100'])->assertRedirect();
        $session = CashSession::query()->firstOrFail();

        $this->from(route('cash-registers.show', $register))
            ->post(route('cash-registers.sessions.close', [$register, $session]), ['closing_amount' => '99'])
            ->assertRedirect(route('cash-registers.show', $register))
            ->assertSessionHasErrors('closing_note');
        $this->assertSame(CashSession::STATUS_OPEN, $session->fresh()->status);

        $this->post(route('cash-registers.sessions.close', [$register, $session]), ['closing_amount' => '100'])->assertRedirect();
        $this->from(route('cash-registers.show', $register))
            ->post(route('cash-registers.sessions.movements.store', [$register, $session]), [
                'type' => 'deposit',
                'amount' => '10',
                'description' => 'Doit échouer',
            ])->assertRedirect(route('cash-registers.show', $register))
            ->assertSessionHasErrors('session');

        $this->from(route('cash-registers.show', $register))
            ->post(route('cash-registers.sessions.close', [$register, $session]), ['closing_amount' => '100'])
            ->assertRedirect(route('cash-registers.show', $register))
            ->assertSessionHasErrors('session');
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_cash_payment_creates_exactly_one_linked_incoming_movement(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.view', 'cash.open', 'cash.move', 'payment.create', 'payment.view']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $this->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '2000'])->assertRedirect();
        $session = CashSession::query()->firstOrFail();
        [$invoice, $method] = $this->makeInvoice($company);

        $this->post(route('invoices.payments.store', $invoice), $this->paymentData($method, $session, '2500.55'))->assertRedirect();

        $payment = Payment::query()->firstOrFail();
        $movement = CashMovement::query()->where('source_type', Payment::class)->where('source_id', $payment->id)->firstOrFail();
        $this->assertSame($session->id, $payment->cash_session_id);
        $this->assertSame($session->id, $movement->cash_session_id);
        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame(CashMovement::TYPE_PAYMENT, $movement->type);
        $this->assertSame(CashMovement::DIRECTION_IN, $movement->direction);
        $this->assertSame('2500.55', $movement->amount);

        app(CashMovementService::class)->recordPaymentMovement($payment, $session, $user);
        $this->assertSame(1, CashMovement::query()->where('source_type', Payment::class)->where('source_id', $payment->id)->count());
    }

    public function test_cash_payment_without_open_session_is_rejected_without_creating_payment(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.move', 'payment.create', 'payment.view']);
        [$invoice, $method] = $this->makeInvoice($company);

        $this->from(route('invoices.payments.index', $invoice))
            ->post(route('invoices.payments.store', $invoice), [
                'amount' => '500',
                'payment_date' => '2026-09-28',
                'payment_method_id' => $method->id,
            ])->assertRedirect(route('invoices.payments.index', $invoice))
            ->assertSessionHasErrors('cash_session_id');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('100000.00', $invoice->fresh()->balance_due);
        $this->assertSame($user->id, auth()->id());
    }

    public function test_payment_and_cash_movement_roll_back_together_if_movement_fails(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.move', 'payment.create']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $this->post(route('cash-registers.sessions.open', $register), ['opening_amount' => '0'])->assertRedirect();
        $session = CashSession::query()->firstOrFail();
        [$invoice, $method] = $this->makeInvoice($company);

        CashMovement::creating(static function (): void {
            throw new RuntimeException('Échec simulé du mouvement.');
        });

        try {
            app(PaymentService::class)->recordPayment(
                $invoice,
                $this->paymentData($method, $session, '1000'),
            );
            $this->fail('Le mouvement en erreur aurait dû annuler la transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Échec simulé du mouvement.', $exception->getMessage());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('0.00', $invoice->fresh()->amount_paid);
        $this->assertSame('100000.00', $invoice->fresh()->balance_due);
        $this->assertSame($user->id, auth()->id());
    }

    public function test_cash_session_must_match_company_and_route_register(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.view', 'cash.open', 'cash.move', 'cash.close']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        [$foreignUser, $otherCompany] = $this->userWithPermissions(['cash.open']);
        $foreignRegister = CashRegister::factory()->create(['company_id' => $otherCompany->id]);
        $this->post(route('cash-registers.sessions.open', $foreignRegister), ['opening_amount' => '0'])->assertRedirect();
        $this->actingAs($user);
        $foreignSession = CashSession::query()->where('cash_register_id', $foreignRegister->id)->firstOrFail();

        $this->post(route('cash-registers.sessions.movements.store', [$register, $foreignSession]), [
            'type' => 'deposit',
            'amount' => '1',
            'description' => 'Mauvais rattachement',
        ])->assertNotFound();
        $this->get(route('cash-registers.show', $foreignRegister))->assertNotFound();
        $this->assertSame($user->company_id, $register->company_id);
    }

    public function test_non_cash_payment_methods_do_not_create_cash_movements(): void
    {
        [$user, $company] = $this->userWithPermissions(['payment.create']);
        [$invoice, $method] = $this->makeInvoice($company, 'bank');
        $this->post(route('invoices.payments.store', $invoice), [
            'amount' => '1000',
            'payment_date' => '2026-09-28',
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertNull(Payment::query()->firstOrFail()->cash_session_id);
        $this->assertSame($user->id, auth()->id());
    }

    private function userWithPermissions(array $permissionSlugs): array
    {
        $company = Company::factory()->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Opérateur test', 'slug' => 'operator']);
        $permissions = collect($permissionSlugs)->map(fn (string $slug): Permission => Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug],
        ));
        $role->permissions()->sync($permissions->pluck('id'));

        $user = User::factory()->for($company)->create();
        $user->roles()->attach($role);
        $this->actingAs($user);

        return [$user, $company];
    }

    private function makeInvoice(Company $company, string $methodCode = 'cash'): array
    {
        $customer = Customer::factory()->for($company)->create();
        $method = PaymentMethod::query()->create([
            'company_id' => $company->id,
            'name' => $methodCode === 'cash' ? 'Espèces' : 'Virement',
            'code' => $methodCode,
            'is_active' => true,
        ]);
        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'number' => 'FAC-'.fake()->unique()->numerify('######'),
            'issue_date' => '2026-09-25',
            'due_date' => '2026-10-25',
            'subtotal' => '100000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '100000.00',
            'amount_paid' => '0.00',
            'balance_due' => '100000.00',
            'status' => 'sent',
        ]);

        return [$invoice, $method];
    }

    private function paymentData(PaymentMethod $method, CashSession $session, string $amount): array
    {
        return [
            'amount' => $amount,
            'payment_date' => '2026-09-28',
            'payment_method_id' => $method->id,
            'cash_session_id' => $session->id,
        ];
    }
}
