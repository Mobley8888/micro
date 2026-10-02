<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialJournalEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CashMovementService;
use App\Services\Commercial\CashSessionService;
use App\Services\Commercial\FinancialOperationsService;
use App\Services\Commercial\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_expense_creates_one_cash_outflow_and_one_journal_entry_idempotently(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.expense', 'expense.view']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '50000.00');
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Fournitures', 'code' => 'SUPPLIES']);
        $method = $this->method($company, 'cash');
        $data = ['expense_category_id' => $category->id, 'description' => 'Papeterie', 'amount' => '10000.50', 'expense_date' => '2026-09-30', 'payment_method_id' => $method->id, 'cash_session_id' => $session->id, 'idempotency_key' => 'cash-exp-1'];
        $service = app(FinancialOperationsService::class);

        $expense = $service->createExpense($user, $data);
        $again = $service->createExpense($user, $data);

        $this->assertSame($expense->id, $again->id);
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, CashMovement::query()->where('source_type', Expense::class)->count());
        $this->assertSame(2, FinancialJournalEntry::query()->count());
        $this->assertSame('39999.50', app(CashSessionService::class)->snapshot($session)['expected_amount']);
    }

    public function test_bank_expense_and_transfer_update_bank_and_cash_as_one_operation(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.expense', 'expense.create', 'bank.transaction', 'bank.view']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '20000.00');
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Compte courant', 'opening_balance' => '200000.00']);
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Transport', 'code' => 'TRANSPORT']);
        $method = $this->method($company, 'bank');
        $expense = app(FinancialOperationsService::class)->createExpense($user, [
            'expense_category_id' => $category->id, 'description' => 'Carburant', 'amount' => '50000', 'expense_date' => '2026-09-30',
            'payment_method_id' => $method->id, 'bank_account_id' => $account->id,
        ]);
        $this->assertNull($expense->cash_session_id);
        $this->assertSame('150000.00', $account->fresh()->balance());
        $this->assertDatabaseCount('cash_movements', 1);

        $transfer = app(FinancialOperationsService::class)->transfer($user, [
            'from_type' => 'cash', 'to_type' => 'bank', 'to_id' => $account->id, 'cash_session_id' => $session->id,
            'amount' => '5000', 'transferred_at' => '2026-09-30 12:00:00', 'idempotency_key' => 'transfer-1',
        ]);
        $this->assertDatabaseCount('financial_transfers', 1);
        $this->assertSame('155000.00', $account->fresh()->balance());
        $this->assertSame('15000.00', app(CashSessionService::class)->snapshot($session)['expected_amount']);
        $this->assertSame(4, FinancialJournalEntry::query()->count());
        $this->assertNotNull($transfer->id);
    }

    public function test_bank_payment_creates_a_linked_bank_credit_and_journal_line(): void
    {
        [$user, $company] = $this->userWithPermissions(['payment.create', 'bank.transaction']);
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Banque test', 'opening_balance' => '50000']);
        $customer = Customer::factory()->for($company)->create();
        $invoice = Invoice::factory()->for($company)->for($customer)->create([
            'number' => 'FAC-'.fake()->unique()->numerify('######'), 'subtotal' => '20000', 'total' => '20000',
            'amount_paid' => '0', 'balance_due' => '20000', 'financial_status' => 'unpaid', 'status' => 'sent',
        ]);
        $method = $this->method($company, 'bank');

        $paymentData = [
            'amount' => '7500.25', 'payment_date' => today()->toDateString(), 'payment_method_id' => $method->id,
            'bank_account_id' => $account->id, 'reference' => 'VIR-001', 'idempotency_key' => (string) fake()->uuid(),
        ];
        $payment = app(PaymentService::class)->recordPayment($invoice, $paymentData);
        $duplicate = app(PaymentService::class)->recordPayment($invoice, $paymentData);

        $this->assertSame($payment->id, $duplicate->id);
        $this->assertSame($account->id, $payment->bank_account_id);
        $this->assertSame('57500.25', $account->fresh()->balance());
        $this->assertDatabaseHas('bank_transactions', ['source_type' => Payment::class, 'source_id' => $payment->id, 'direction' => 'in']);
        $this->assertDatabaseHas('financial_journal_entries', ['account_type' => 'bank', 'source_type' => BankTransaction::class]);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_manual_cash_movement_is_idempotent_and_cannot_overdraw(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.move']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '1000');
        $data = ['type' => 'withdrawal', 'amount' => '250', 'description' => 'Sortie test', 'idempotency_key' => (string) fake()->uuid()];
        $service = app(CashMovementService::class);
        $first = $service->recordManualMovement($user, $session, $data);
        $second = $service->recordManualMovement($user, $session, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $session->movements()->count());
        $this->assertSame('750.00', app(CashSessionService::class)->snapshot($session)['expected_amount']);
        try {
            $service->recordManualMovement($user, $session, ['type' => 'withdrawal', 'amount' => '751', 'description' => 'Trop grand']);
            $this->fail('Un retrait supérieur au solde aurait dû être refusé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    public function test_expense_justificatif_is_private_and_company_scoped(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.expense', 'expense.view']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '5000');
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Justificatif', 'code' => 'ATTACH']);
        $method = $this->method($company, 'cash');

        $this->post(route('expenses.store'), [
            'expense_category_id' => $category->id, 'description' => 'Dépense avec pièce', 'amount' => '500',
            'expense_date' => today()->toDateString(), 'payment_method_id' => $method->id,
            'cash_session_id' => $session->id, 'attachment' => UploadedFile::fake()->image('receipt.png'),
            'idempotency_key' => (string) fake()->uuid(),
        ])->assertRedirect();
        $expense = Expense::query()->firstOrFail();

        Storage::disk('local')->assertExists($expense->attachment_path);
        $this->get(route('expenses.attachment', $expense))->assertOk();
        [$foreignUser] = $this->userWithPermissions(['expense.view']);
        $this->actingAs($foreignUser)->get(route('expenses.attachment', $expense))->assertNotFound();
    }

    public function test_manual_cash_outflow_keeps_details_and_private_attachment(): void
    {
        Storage::fake('local');
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.move', 'cash.view']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '5000');

        $this->post(route('cash-registers.sessions.movements.store', [$register, $session]), [
            'type' => 'withdrawal', 'amount' => '500', 'description' => 'Frais de livraison',
            'beneficiary' => 'Transporteur local', 'reference' => 'SORTIE-42', 'notes' => 'Course urgente',
            'occurred_at' => '2026-09-30T10:15', 'attachment' => UploadedFile::fake()->image('justificatif.png'),
            'idempotency_key' => (string) fake()->uuid(),
        ])->assertRedirect();

        $movement = CashMovement::query()->where('description', 'Frais de livraison')->firstOrFail();
        $this->assertSame('2026-09-30 10:15:00', $movement->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('Transporteur local', $movement->metadata['beneficiary']);
        $this->assertSame('Course urgente', $movement->metadata['notes']);
        Storage::disk('local')->assertExists($movement->metadata['attachment_path']);
        $this->get(route('cash-movements.attachment', $movement))->assertOk();

        [$foreignUser] = $this->userWithPermissions(['cash.view']);
        $this->actingAs($foreignUser)->get(route('cash-movements.attachment', $movement))->assertNotFound();
    }

    public function test_manual_bank_transaction_is_idempotent(): void
    {
        [$user] = $this->userWithPermissions(['bank.transaction']);
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Idempotence', 'opening_balance' => '1000']);
        $data = [
            'type' => 'deposit', 'direction' => 'in', 'amount' => '250', 'transaction_date' => now(),
            'description' => 'Dépôt idempotent', 'idempotency_key' => (string) fake()->uuid(),
        ];
        $service = app(FinancialOperationsService::class);
        $first = $service->recordBankTransaction($user, $account, $data);
        $second = $service->recordBankTransaction($user, $account, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $account->transactions()->count());
        $this->assertSame(1, FinancialJournalEntry::query()->count());
    }

    public function test_expense_cancellation_records_a_reversal_without_deleting_the_original(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.expense', 'expense.cancel']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '10000');
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Divers', 'code' => 'OTHER']);
        $method = $this->method($company, 'cash');
        $expense = app(FinancialOperationsService::class)->createExpense($user, [
            'expense_category_id' => $category->id, 'description' => 'Test annulation', 'amount' => '2500',
            'expense_date' => '2026-09-30', 'payment_method_id' => $method->id, 'cash_session_id' => $session->id,
        ]);

        $this->post(route('expenses.cancel', $expense), ['reason' => 'Saisie erronée'])->assertRedirect();

        $this->assertSame('cancelled', $expense->fresh()->status);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_reversals', 1);
        $this->assertSame('10000.00', app(CashSessionService::class)->snapshot($session)['expected_amount']);
        $this->assertSame(3, FinancialJournalEntry::query()->count());
    }

    public function test_expense_and_report_pages_are_company_scoped(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.expense', 'expense.view', 'financial.report.view']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '10000');
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Achat', 'code' => 'BUY']);
        $method = $this->method($company, 'cash');
        $expense = app(FinancialOperationsService::class)->createExpense($user, [
            'expense_category_id' => $category->id, 'description' => 'Dépense locale', 'amount' => '100',
            'expense_date' => today()->toDateString(), 'payment_method_id' => $method->id, 'cash_session_id' => $session->id,
        ]);
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->for($otherCompany)->create();
        ExpenseCategory::query()->create(['company_id' => $otherCompany->id, 'name' => 'Autre', 'code' => 'OTHER']);
        $this->actingAs($user);

        $this->get(route('expenses.index'))->assertOk()->assertSee($expense->description)->assertDontSee('Dépense étrangère');
        $this->get(route('financial.reports'))->assertOk()->assertSee('Dépenses')->assertDontSee($otherUser->name);
    }

    public function test_foreign_bank_accounts_are_hidden_and_permissions_are_enforced(): void
    {
        [$user, $company] = $this->userWithPermissions(['bank.view']);
        $foreignCompany = Company::factory()->create();
        $foreignUser = User::factory()->for($foreignCompany)->create();
        $foreignAccount = BankAccount::query()->create(['company_id' => $foreignCompany->id, 'name' => 'Banque privée', 'opening_balance' => 1, 'created_by' => $foreignUser->id]);

        $this->get(route('banks.index'))->assertOk()->assertDontSee('Banque privée');
        $this->get(route('banks.transactions', $foreignAccount))->assertNotFound();
        $this->get(route('expenses.index'))->assertForbidden();
        $this->assertSame($company->id, $user->company_id);
    }

    public function test_expense_form_renders_company_scoped_selection_data(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.expense', 'expense.create', 'cash.open']);
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Fournitures locales', 'code' => 'LOCAL_SUPPLIES']);
        $cashMethod = $this->method($company, 'cash');
        $bankMethod = $this->method($company, 'bank');
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '10000');
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Compte local', 'opening_balance' => '10000']);

        $foreignCompany = Company::factory()->create();
        $foreignCategory = ExpenseCategory::query()->create(['company_id' => $foreignCompany->id, 'name' => 'Catégorie privée', 'code' => 'FOREIGN_CATEGORY']);
        $foreignUser = User::factory()->for($foreignCompany)->create();
        $foreignAccount = BankAccount::query()->create(['company_id' => $foreignCompany->id, 'name' => 'Compte privé', 'opening_balance' => '10000', 'created_by' => $foreignUser->id]);

        $this->get(route('expenses.create'))
            ->assertOk()
            ->assertSee('Sélectionner une catégorie')
            ->assertSee('Fournitures locales')
            ->assertSee($cashMethod->name)
            ->assertSee($bankMethod->name)
            ->assertSee($session->cashRegister->name)
            ->assertSee($account->name)
            ->assertDontSee($foreignCategory->name)
            ->assertDontSee($foreignAccount->name);
    }

    public function test_expense_form_enforces_cash_session_and_rejects_foreign_bank_account(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.expense', 'expense.create']);
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Opérations', 'code' => 'OPERATIONS']);
        $cashMethod = $this->method($company, 'cash');
        $this->post(route('expenses.store'), [
            'expense_category_id' => $category->id, 'description' => 'Dépense sans session', 'amount' => '500',
            'expense_date' => today()->toDateString(), 'payment_method_id' => $cashMethod->id,
        ])->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('expenses', 0);

        $bankMethod = $this->method($company, 'bank');
        $foreignCompany = Company::factory()->create();
        $foreignUser = User::factory()->for($foreignCompany)->create();
        $foreignAccount = BankAccount::query()->create(['company_id' => $foreignCompany->id, 'name' => 'Compte hors société', 'opening_balance' => '10000', 'created_by' => $foreignUser->id]);
        $this->post(route('expenses.store'), [
            'expense_category_id' => $category->id, 'description' => 'Compte étranger', 'amount' => '500',
            'expense_date' => today()->toDateString(), 'payment_method_id' => $bankMethod->id,
            'bank_account_id' => $foreignAccount->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_transfer_page_lists_only_active_company_accounts_and_submission_validation(): void
    {
        [$user, $company] = $this->userWithPermissions(['bank.transaction', 'cash.open']);
        $localAccount = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Banque visible', 'opening_balance' => '10000']);
        $foreignCompany = Company::factory()->create();
        $foreignUser = User::factory()->for($foreignCompany)->create();
        $foreignAccount = BankAccount::query()->create(['company_id' => $foreignCompany->id, 'name' => 'Banque cachée', 'opening_balance' => '10000', 'created_by' => $foreignUser->id]);

        $this->get(route('financial-transfers.create'))
            ->assertOk()
            ->assertSee('Sélectionner le compte source')
            ->assertSee('Sélectionner le compte destination')
            ->assertSee($localAccount->name)
            ->assertDontSee($foreignAccount->name);
        $this->post(route('financial-transfers.store'), [
            'from_type' => 'bank', 'from_id' => $localAccount->id,
            'to_type' => 'bank', 'to_id' => $localAccount->id,
            'amount' => '100', 'transferred_at' => now()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('to_type');
        $this->post(route('financial-transfers.store'), [
            'from_type' => 'bank', 'from_id' => $foreignAccount->id,
            'to_type' => 'cash', 'amount' => '100', 'transferred_at' => now()->format('Y-m-d H:i:s'),
        ])->assertNotFound();
        $this->assertDatabaseCount('financial_transfers', 0);
    }

    private function userWithPermissions(array $slugs): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Test finances', 'slug' => 'finance-test']);
        $permissions = collect($slugs)->map(fn (string $slug) => Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        $role->permissions()->sync($permissions->pluck('id'));
        $user->roles()->attach($role);
        $this->actingAs($user);

        return [$user, $company];
    }

    private function method(Company $company, string $code): PaymentMethod
    {
        return PaymentMethod::query()->create(['company_id' => $company->id, 'name' => $code === 'cash' ? 'Espèces' : 'Virement', 'code' => $code, 'is_active' => true]);
    }
}
