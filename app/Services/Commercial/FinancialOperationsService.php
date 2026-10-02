<?php

namespace App\Services\Commercial;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReversal;
use App\Models\FinancialTransfer;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CashAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialOperationsService
{
    public function __construct(private readonly CashSessionService $cashSessionService) {}

    public function createExpense(User $user, array $data): Expense
    {
        $companyId = $this->companyId($user, $data);

        return DB::transaction(function () use ($user, $data, $companyId): Expense {
            Company::query()->whereKey($companyId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if (! empty($data['idempotency_key'])) {
                $existing = Expense::query()->where('company_id', $companyId)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }
            $category = ExpenseCategory::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($data['expense_category_id']);
            $method = PaymentMethod::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($data['payment_method_id']);
            $amountCents = CashAmount::toCents((string) $data['amount']);
            if ($amountCents <= 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro.']);
            }

            $session = null;
            $account = null;
            if ($method->code === 'cash') {
                abort_unless($user->hasPermission('cash.expense') || $user->hasPermission('expense.create'), 403);
                $session = CashSession::query()->where('company_id', $companyId)->whereKey($data['cash_session_id'] ?? null)->whereHas('cashRegister', fn ($query) => $query->where('is_active', true))->lockForUpdate()->first();
                if (! $session || $session->status !== CashSession::STATUS_OPEN) {
                    throw ValidationException::withMessages(['cash_session_id' => 'Aucune session de caisse ouverte. Ouvrez votre caisse avant d’enregistrer cette dépense en espèces.']);
                }
                $balance = CashAmount::toCents($this->cashSessionService->snapshot($session)['expected_amount']);
                if ($balance < $amountCents) {
                    throw ValidationException::withMessages(['amount' => 'Le solde disponible en caisse est insuffisant.']);
                }
            } else {
                abort_unless($user->hasPermission('expense.create'), 403);
                if (empty($data['bank_account_id'])) {
                    throw ValidationException::withMessages(['bank_account_id' => 'Sélectionnez le compte financier utilisé pour cette dépense.']);
                }
                $account = BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->lockForUpdate()->findOrFail($data['bank_account_id']);
                if (CashAmount::toCents($account->balance()) < $amountCents) {
                    throw ValidationException::withMessages(['amount' => 'Le solde bancaire disponible est insuffisant.']);
                }
            }

            $expense = Expense::query()->create([
                'company_id' => $companyId, 'expense_category_id' => $category->id,
                'supplier' => $data['supplier'] ?? null, 'reference' => $data['reference'] ?? null,
                'description' => $data['description'], 'amount' => CashAmount::fromCents($amountCents),
                'expense_date' => $data['expense_date'], 'payment_method_id' => $method->id,
                'cash_session_id' => $session?->id, 'bank_account_id' => $account?->id,
                'status' => 'posted', 'notes' => $data['notes'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null, 'created_by' => $user->id,
            ]);

            if ($session) {
                $movement = $session->movements()->create([
                    'company_id' => $companyId, 'cash_register_id' => $session->cash_register_id,
                    'type' => CashMovement::TYPE_WITHDRAWAL, 'direction' => CashMovement::DIRECTION_OUT,
                    'amount' => $expense->amount, 'occurred_at' => $data['expense_date'],
                    'description' => $expense->description, 'reference' => $expense->reference,
                    'source_type' => Expense::class, 'source_id' => $expense->id, 'created_by' => $user->id,
                ]);
                $expense->setRelation('cashSession', $session);
                $movement->exists;
            } else {
                $transaction = $account->transactions()->create([
                    'company_id' => $companyId, 'type' => 'expense', 'direction' => 'out',
                    'amount' => $expense->amount, 'transaction_date' => $data['expense_date'],
                    'reference' => $expense->reference, 'description' => $expense->description,
                    'counterparty' => $expense->supplier, 'status' => 'posted', 'source_type' => Expense::class,
                    'source_id' => $expense->id, 'created_by' => $user->id, 'notes' => $expense->notes,
                ]);
                $transaction->exists;
            }

            return $expense->load(['category', 'paymentMethod', 'createdBy']);
        }, attempts: 3);
    }

    public function createCategory(User $user, array $data): ExpenseCategory
    {
        $companyId = $this->companyId($user, $data);

        return ExpenseCategory::query()->create(['company_id' => $companyId, 'name' => $data['name'], 'code' => $data['code'], 'description' => $data['description'] ?? null, 'is_active' => true]);
    }

    public function createBankAccount(User $user, array $data): BankAccount
    {
        $companyId = $this->companyId($user, $data);

        return BankAccount::query()->create([
            'company_id' => $companyId, 'name' => $data['name'], 'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null, 'account_type' => $data['account_type'] ?? 'current',
            'currency' => $data['currency'] ?? 'XAF', 'opening_balance' => $data['opening_balance'] ?? '0',
            'is_active' => true, 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
        ]);
    }

    public function recordBankTransaction(User $user, BankAccount $bankAccount, array $data): BankTransaction
    {
        abort_unless($user->isSuperAdmin() || $bankAccount->company_id === $user->company_id, 404);

        return DB::transaction(function () use ($user, $bankAccount, $data): BankTransaction {
            Company::query()->whereKey($bankAccount->company_id)->lockForUpdate()->firstOrFail();
            $account = BankAccount::query()->whereKey($bankAccount->id)->where('company_id', $bankAccount->company_id)->lockForUpdate()->firstOrFail();
            if (! empty($data['idempotency_key'])) {
                $existing = BankTransaction::query()->where('company_id', $account->company_id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing !== null) {
                    abort_unless($existing->bank_account_id === $account->id, 409, 'Cette clé est déjà utilisée pour une autre transaction.');

                    return $existing;
                }
            }
            $cents = CashAmount::toCents((string) $data['amount']);
            if ($cents <= 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro.']);
            }
            $direction = $data['direction'];
            if ($direction === 'out' && CashAmount::toCents($account->balance()) < $cents) {
                throw ValidationException::withMessages(['amount' => 'Le solde bancaire disponible est insuffisant.']);
            }
            $transaction = $account->transactions()->create([
                'company_id' => $account->company_id, 'type' => $data['type'], 'direction' => $direction,
                'amount' => CashAmount::fromCents($cents), 'transaction_date' => $data['transaction_date'],
                'reference' => $data['reference'] ?? null, 'description' => $data['description'],
                'counterparty' => $data['counterparty'] ?? null, 'status' => 'posted', 'created_by' => $user->id,
                'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            return $transaction;
        }, attempts: 3);
    }

    public function transfer(User $user, array $data): FinancialTransfer
    {
        $companyId = $this->companyId($user, $data);

        return DB::transaction(function () use ($user, $data, $companyId): FinancialTransfer {
            Company::query()->whereKey($companyId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if (! empty($data['idempotency_key'])) {
                $existing = FinancialTransfer::query()->where('company_id', $companyId)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }
            $amount = CashAmount::toCents((string) $data['amount']);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro.']);
            }
            $fromType = $data['from_type'];
            $toType = $data['to_type'];
            if ($fromType === $toType || ! in_array($fromType, ['cash', 'bank'], true) || ! in_array($toType, ['cash', 'bank'], true)) {
                throw ValidationException::withMessages(['to_type' => 'Choisissez deux comptes de nature différente.']);
            }
            if (! in_array([$fromType, $toType], [['cash', 'bank'], ['bank', 'cash']], true)) {
                throw ValidationException::withMessages(['to_type' => 'Les transferts doivent relier une caisse et une banque.']);
            }
            $session = null;
            $from = null;
            $to = null;
            if ($fromType === 'cash') {
                $session = CashSession::query()->where('company_id', $companyId)->whereKey($data['cash_session_id'] ?? null)->whereHas('cashRegister', fn ($query) => $query->where('is_active', true))->lockForUpdate()->firstOrFail();
                if ($session->status !== 'open' || CashAmount::toCents($this->cashSessionService->snapshot($session)['expected_amount']) < $amount) {
                    throw ValidationException::withMessages(['cash_session_id' => 'La caisse est fermée ou son solde est insuffisant.']);
                }
            } else {
                $from = BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->whereKey($data['from_id'])->lockForUpdate()->firstOrFail();
                if (CashAmount::toCents($from->balance()) < $amount) {
                    throw ValidationException::withMessages(['amount' => 'Le solde bancaire disponible est insuffisant.']);
                }
            }
            if ($toType === 'bank') {
                $to = BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->whereKey($data['to_id'])->lockForUpdate()->firstOrFail();
            }
            if ($fromType === 'cash') {
                $fromId = $session->cash_register_id;
            } else {
                $fromId = $from->id;
            }
            if ($toType === 'cash') {
                $session = CashSession::query()->where('company_id', $companyId)->whereKey($data['cash_session_id'] ?? null)->whereHas('cashRegister', fn ($query) => $query->where('is_active', true))->lockForUpdate()->firstOrFail();
                if ($session->status !== 'open') {
                    throw ValidationException::withMessages(['cash_session_id' => 'Une session de caisse ouverte est nécessaire.']);
                }
                $toId = $session->cash_register_id;
            } else {
                $toId = $to->id;
            }
            $transfer = FinancialTransfer::query()->create([
                'company_id' => $companyId, 'from_type' => $fromType, 'from_id' => $fromId,
                'to_type' => $toType, 'to_id' => $toId, 'amount' => CashAmount::fromCents($amount),
                'transferred_at' => $data['transferred_at'], 'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? 'Transfert interne', 'created_by' => $user->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);
            foreach ([[$fromType, $fromId, 'out', $session, $from], [$toType, $toId, 'in', $session, $to]] as [$type, $id, $direction, $cashSession, $bankAccount]) {
                if ($type === 'cash') {
                    $cashSession->movements()->create([
                        'company_id' => $companyId, 'cash_register_id' => $cashSession->cash_register_id,
                        'type' => $direction === 'out' ? 'withdrawal' : 'deposit', 'direction' => $direction,
                        'amount' => $transfer->amount, 'occurred_at' => $transfer->transferred_at,
                        'description' => 'Transfert financier', 'reference' => $transfer->reference,
                        'source_type' => FinancialTransfer::class, 'source_id' => $transfer->id, 'created_by' => $user->id,
                    ]);
                } else {
                    $bankAccount->transactions()->create([
                        'company_id' => $companyId, 'type' => 'transfer', 'direction' => $direction,
                        'amount' => $transfer->amount, 'transaction_date' => $transfer->transferred_at,
                        'reference' => $transfer->reference, 'description' => 'Transfert financier', 'status' => 'posted',
                        'source_type' => FinancialTransfer::class, 'source_id' => $transfer->id, 'created_by' => $user->id,
                    ]);
                }
            }

            return $transfer;
        }, attempts: 3);
    }

    public function cancelExpense(User $user, Expense $expense, string $reason): Expense
    {
        abort_unless($user->isSuperAdmin() || $expense->company_id === $user->company_id, 404);

        return DB::transaction(function () use ($user, $expense, $reason): Expense {
            $locked = Expense::query()->whereKey($expense->id)->where('company_id', $expense->company_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'posted') {
                throw ValidationException::withMessages(['expense' => 'Cette dépense est déjà annulée.']);
            }
            $reversal = ExpenseReversal::query()->create([
                'company_id' => $locked->company_id, 'expense_id' => $locked->id,
                'amount' => $locked->amount, 'reason' => $reason, 'created_by' => $user->id,
            ]);
            if ($locked->cash_session_id !== null) {
                $session = CashSession::query()->where('company_id', $locked->company_id)->where('status', 'open')->lockForUpdate()->first();
                if (! $session) {
                    throw ValidationException::withMessages(['expense' => 'Ouvrez une session de caisse pour enregistrer le remboursement de cette dépense.']);
                }
                $session->movements()->create([
                    'company_id' => $locked->company_id, 'cash_register_id' => $session->cash_register_id,
                    'type' => 'deposit', 'direction' => 'in', 'amount' => $locked->amount, 'occurred_at' => now(),
                    'description' => 'Contrepassation dépense '.$locked->description, 'reference' => $locked->reference,
                    'source_type' => ExpenseReversal::class, 'source_id' => $reversal->id, 'created_by' => $user->id,
                ]);
            } else {
                $account = BankAccount::query()->whereKey($locked->bank_account_id)->where('company_id', $locked->company_id)->lockForUpdate()->firstOrFail();
                $account->transactions()->create([
                    'company_id' => $locked->company_id, 'type' => 'adjustment', 'direction' => 'in',
                    'amount' => $locked->amount, 'transaction_date' => now(), 'reference' => $locked->reference,
                    'description' => 'Contrepassation dépense '.$locked->description, 'status' => 'posted',
                    'source_type' => ExpenseReversal::class, 'source_id' => $reversal->id,
                    'created_by' => $user->id,
                ]);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function reconcile(User $user, BankTransaction $transaction, bool $reconciled): void
    {
        abort_unless($user->isSuperAdmin() || $transaction->company_id === $user->company_id, 404);
        DB::transaction(function () use ($user, $transaction, $reconciled): void {
            $locked = BankTransaction::query()->whereKey($transaction->id)->where('company_id', $transaction->company_id)->lockForUpdate()->firstOrFail();
            $locked->update(['reconciled_at' => $reconciled ? now() : null, 'reconciled_by' => $reconciled ? $user->id : null]);
        });
    }

    private function companyId(User $user, array $data): string
    {
        if ($user->isSuperAdmin()) {
            $id = $data['company_id'] ?? $user->company_id;
            abort_unless($id !== null, 422, 'Sélectionnez une entreprise.');

            return (string) $id;
        }
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
