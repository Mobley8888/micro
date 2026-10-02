<?php

namespace Tests\PostgreSQL;

use App\Models\BankTransaction;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\FinancialJournalEntry;
use App\Models\FinancialTransfer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Commercial\CashSessionService;
use App\Services\Commercial\FinancialOperationsService;
use App\Services\Commercial\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class FinancialOperationsPostgreSQLTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'), 'Ce test doit utiliser PostgreSQL.');
    }

    public function test_postgresql_cash_and_bank_operations_create_atomic_journal_entries(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'cash.expense', 'expense.create', 'bank.transaction']);
        $service = app(FinancialOperationsService::class);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '50000');
        $account = $service->createBankAccount($user, ['name' => 'Test bank PG', 'opening_balance' => '200000']);
        $category = ExpenseCategory::query()->create(['company_id' => $company->id, 'name' => 'Test PG', 'code' => 'PGTEST']);
        $cashMethod = $this->method($company, 'cash');
        $bankMethod = $this->method($company, 'bank');

        $cashExpense = $service->createExpense($user, [
            'expense_category_id' => $category->id, 'description' => 'Dépense PG espèces', 'amount' => '1000.25',
            'expense_date' => today()->toDateString(), 'payment_method_id' => $cashMethod->id,
            'cash_session_id' => $session->id,
        ]);
        $bankExpense = $service->createExpense($user, [
            'expense_category_id' => $category->id, 'description' => 'Dépense PG banque', 'amount' => '2500',
            'expense_date' => today()->toDateString(), 'payment_method_id' => $bankMethod->id,
            'bank_account_id' => $account->id,
        ]);
        $service->recordBankTransaction($user, $account, [
            'type' => 'deposit', 'direction' => 'in', 'amount' => '500', 'transaction_date' => now(),
            'description' => 'Crédit PG',
        ]);
        $service->transfer($user, [
            'from_type' => 'cash', 'to_type' => 'bank', 'to_id' => $account->id,
            'cash_session_id' => $session->id, 'amount' => '2000', 'transferred_at' => now(),
            'idempotency_key' => 'pg-transfer-'.fake()->uuid(),
        ]);
        $service->transfer($user, [
            'from_type' => 'bank', 'from_id' => $account->id, 'to_type' => 'cash',
            'cash_session_id' => $session->id, 'amount' => '1000', 'transferred_at' => now(),
            'idempotency_key' => 'pg-transfer-'.fake()->uuid(),
        ]);

        $this->assertDatabaseHas('expenses', ['id' => $cashExpense->id, 'company_id' => $company->id]);
        $this->assertDatabaseHas('expenses', ['id' => $bankExpense->id, 'bank_account_id' => $account->id]);
        $this->assertSame(4, CashMovement::query()->where('cash_session_id', $session->id)->count());
        $this->assertSame(4, BankTransaction::query()->where('bank_account_id', $account->id)->count());
        $this->assertSame(8, FinancialJournalEntry::query()->where('company_id', $company->id)->count());
        $this->assertSame('199000.00', $account->fresh()->balance());
    }

    public function test_postgresql_invoice_payment_posts_a_single_bank_credit(): void
    {
        [$user, $company] = $this->userWithPermissions(['payment.create']);
        $this->actingAs($user);
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Payments PG', 'opening_balance' => '3000']);
        $customer = Customer::factory()->for($company)->create();
        $invoice = Invoice::factory()->for($company)->for($customer)->create([
            'number' => 'PG-'.fake()->unique()->numerify('####'), 'subtotal' => '10000', 'total' => '10000',
            'amount_paid' => '0', 'balance_due' => '10000', 'financial_status' => 'unpaid', 'status' => 'sent',
        ]);
        $method = PaymentMethod::query()->create(['company_id' => $company->id, 'name' => 'Virement PG', 'code' => 'bank', 'is_active' => true]);

        $payment = app(PaymentService::class)->recordPayment($invoice, [
            'amount' => '2500', 'payment_date' => today()->toDateString(), 'payment_method_id' => $method->id,
            'bank_account_id' => $account->id,
        ]);

        $this->assertSame($account->id, $payment->bank_account_id);
        $this->assertSame('5500.00', $account->fresh()->balance());
        $transaction = BankTransaction::query()->where('source_type', Payment::class)->where('source_id', $payment->id)->firstOrFail();
        $this->assertSame(1, FinancialJournalEntry::query()->where('account_type', 'bank')->where('source_type', BankTransaction::class)->where('source_id', $transaction->id)->count());
    }

    public function test_postgresql_financial_pages_render_and_company_scoped_actions_work(): void
    {
        [$user, $company] = $this->userWithPermissions([
            'expense.view', 'cash.expense', 'expense.create', 'bank.view', 'bank.create',
            'bank.transaction', 'bank.reconcile', 'financial.journal.view', 'financial.report.view',
        ]);
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Interface PG', 'opening_balance' => '10000']);
        $this->actingAs($user);

        $this->get(route('expenses.index'))->assertOk();
        $this->get(route('banks.index'))->assertOk()->assertSee('Interface PG');
        $this->get(route('financial-transfers.create'))->assertOk()->assertSee('Interface PG');
        $this->get(route('banks.transactions', $account))->assertOk();
        $this->get(route('financial.journal'))->assertOk();
        $this->get(route('financial.reports'))->assertOk();

        $this->post(route('banks.transactions.store', $account), [
            'type' => 'deposit', 'direction' => 'in', 'amount' => '1250.50',
            'transaction_date' => now()->format('Y-m-d H:i:s'), 'description' => 'Crédit interface PG',
        ])->assertRedirect();
        $transaction = BankTransaction::query()->where('description', 'Crédit interface PG')->firstOrFail();
        $this->get(route('banks.transactions', ['bankAccount' => $account, 'statement_balance' => '11252.50']))
            ->assertOk()->assertSee('2,00');
        $this->patch(route('banks.reconcile', $transaction), ['reconciled' => true])->assertRedirect();
        $this->assertNotNull($transaction->fresh()->reconciled_at);
    }

    public function test_postgresql_rolls_back_both_sides_of_a_transfer_if_journal_creation_fails(): void
    {
        [$user, $company] = $this->userWithPermissions(['cash.open', 'bank.transaction']);
        $register = CashRegister::factory()->create(['company_id' => $company->id]);
        $session = app(CashSessionService::class)->open($user, $register, '10000');
        $account = app(FinancialOperationsService::class)->createBankAccount($user, ['name' => 'Rollback bank', 'opening_balance' => '0']);
        $movementCount = CashMovement::query()->count();
        $transactionCount = BankTransaction::query()->count();
        $transferCount = FinancialTransfer::query()->count();
        Event::listen('eloquent.creating: '.FinancialJournalEntry::class, static function (): void {
            throw new RuntimeException('PG rollback check');
        });

        try {
            app(FinancialOperationsService::class)->transfer($user, [
                'from_type' => 'cash', 'to_type' => 'bank', 'to_id' => $account->id,
                'cash_session_id' => $session->id, 'amount' => '1000', 'transferred_at' => now(),
            ]);
            $this->fail('L’exception attendue n’a pas été levée.');
        } catch (RuntimeException $exception) {
            $this->assertSame('PG rollback check', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.FinancialJournalEntry::class);
        }

        $this->assertSame($movementCount, CashMovement::query()->count());
        $this->assertSame($transactionCount, BankTransaction::query()->count());
        $this->assertSame($transferCount, FinancialTransfer::query()->count());
    }

    private function userWithPermissions(array $slugs): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'PG finance test', 'slug' => 'pg-finance-'.fake()->uuid()]);
        $permissions = collect($slugs)->map(fn (string $slug) => Permission::query()->firstOrCreate(['slug' => $slug], ['name' => $slug]));
        $role->permissions()->sync($permissions->pluck('id'));
        $user->roles()->attach($role);

        return [$user, $company];
    }

    private function method(Company $company, string $code): PaymentMethod
    {
        return PaymentMethod::query()->create(['company_id' => $company->id, 'name' => $code, 'code' => $code, 'is_active' => true]);
    }
}
