<?php

namespace App\Services\Commercial;

use App\Models\BankAccount;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\CashAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly NumberingService $numberingService,
        private readonly ReceivableService $receivableService,
        private readonly CashMovementService $cashMovementService,
    ) {}

    /**
     * @param  array{amount: numeric-string|int|float, payment_date: string, payment_method_id: string, cash_session_id?: ?string, reference?: ?string, notes?: ?string}  $data
     */
    public function recordPayment(Invoice $invoice, array $data): Payment
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->hasPermission('payment.create'), 403);
        abort_unless($user->company_id !== null || $user->isSuperAdmin(), 404);

        return DB::transaction(function () use ($invoice, $data, $user): Payment {
            $companyId = $user->isSuperAdmin() ? (string) $invoice->company_id : (string) $user->company_id;
            $lockedInvoice = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status === 'cancelled') {
                throw ValidationException::withMessages(['invoice' => 'Une facture annulée ne peut pas recevoir de paiement.']);
            }

            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if (! empty($data['idempotency_key'])) {
                $existingPayment = Payment::query()->where('company_id', $companyId)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existingPayment !== null) {
                    abort_unless($existingPayment->invoice_id === $lockedInvoice->id, 409, 'Cette clé est déjà utilisée pour un autre paiement.');

                    return $existingPayment->load(['company', 'invoice', 'paymentMethod', 'receivedBy', 'cashSession']);
                }
            }
            $this->receivableService->synchronize($lockedInvoice);
            $lockedInvoice->refresh();

            $paymentMethod = PaymentMethod::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find($data['payment_method_id']);

            if ($paymentMethod === null) {
                throw ValidationException::withMessages(['payment_method_id' => 'Le mode de paiement est invalide.']);
            }

            $cashSession = null;
            $bankAccount = null;
            if ($paymentMethod->code === 'cash') {
                abort_unless($user->hasPermission('cash.move'), 403);
                $cashSessionId = $data['cash_session_id'] ?? CashSession::query()
                    ->where('opened_by', $user->id)
                    ->where('company_id', $companyId)
                    ->where('status', CashSession::STATUS_OPEN)
                    ->value('id');

                if (blank($cashSessionId)) {
                    throw ValidationException::withMessages(['cash_session_id' => 'Aucune session de caisse ouverte. Ouvrez votre caisse avant d’enregistrer un paiement en espèces.']);
                }

                $cashSession = CashSession::query()
                    ->whereKey($cashSessionId)
                    ->where('company_id', $companyId)
                    ->where('status', CashSession::STATUS_OPEN)
                    ->whereHas('cashRegister', fn ($query) => $query->where('is_active', true))
                    ->lockForUpdate()
                    ->first();

                if ($cashSession === null || ($user->isOperator() && $cashSession->opened_by !== $user->id)) {
                    throw ValidationException::withMessages(['cash_session_id' => 'Aucune session de caisse active ne correspond à votre compte.']);
                }
            } elseif (filled($data['cash_session_id'] ?? null)) {
                throw ValidationException::withMessages(['cash_session_id' => 'Une session de caisse ne peut être associée qu’à un paiement en espèces.']);
            } elseif (filled($data['bank_account_id'] ?? null)) {
                $bankAccount = BankAccount::query()->where('company_id', $companyId)->where('is_active', true)
                    ->whereKey($data['bank_account_id'])->lockForUpdate()->first();
                if ($bankAccount === null) {
                    throw ValidationException::withMessages(['bank_account_id' => 'Le compte bancaire sélectionné est inactif ou invalide.']);
                }
            } elseif (BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['bank_account_id' => 'Sélectionnez le compte bancaire qui reçoit le paiement.']);
            }

            $amountCents = CashAmount::toCents((string) $data['amount']);
            $invoiceTotalCents = CashAmount::toCents($lockedInvoice->total);
            $postedPaymentsCents = CashAmount::toCents((string) $lockedInvoice->payments()
                ->where('company_id', $companyId)
                ->where('status', 'posted')
                ->sum('amount'));
            $storedBalanceCents = CashAmount::toCents($lockedInvoice->balance_due);
            $calculatedBalanceCents = max(0, $invoiceTotalCents - $postedPaymentsCents);
            $remainingCents = min($storedBalanceCents, $calculatedBalanceCents);

            if ($remainingCents <= 0) {
                throw ValidationException::withMessages(['amount' => 'Cette facture est déjà entièrement payée.']);
            }

            if ($amountCents <= 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant du paiement doit être supérieur à zéro.']);
            }

            if ($amountCents > $remainingCents) {
                throw ValidationException::withMessages(['amount' => 'Le paiement ne peut pas dépasser le solde restant.']);
            }

            $balanceAfterCents = max(0, $invoiceTotalCents - $postedPaymentsCents - $amountCents);
            $paymentDate = CarbonImmutable::parse($data['payment_date'])->startOfDay();
            $payment = Payment::query()->create([
                'company_id' => $companyId,
                'invoice_id' => $lockedInvoice->id,
                'customer_id' => $lockedInvoice->customer_id,
                'payment_method_id' => $paymentMethod->id,
                'cash_session_id' => $cashSession?->id,
                'bank_account_id' => $bankAccount?->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'received_by' => $user->id,
                'number' => $this->numberingService->next($companyId, 'PAY', $paymentDate->year, 'ENC'),
                'receipt_number' => $this->numberingService->next($companyId, 'REC', $paymentDate->year, 'REC'),
                'amount' => $data['amount'],
                'balance_after' => CashAmount::fromCents($balanceAfterCents),
                'paid_at' => $paymentDate,
                'reference' => $data['reference'] ?? null,
                'status' => 'posted',
                'notes' => $data['notes'] ?? null,
            ]);

            if ($cashSession !== null) {
                $this->cashMovementService->recordPaymentMovement($payment, $cashSession, $user);
            } elseif ($bankAccount !== null) {
                $bankAccount->transactions()->create([
                    'company_id' => $companyId, 'type' => 'deposit', 'direction' => 'in', 'amount' => $payment->amount,
                    'transaction_date' => $payment->paid_at, 'reference' => $payment->reference,
                    'description' => 'Paiement facture '.$lockedInvoice->number, 'counterparty' => $lockedInvoice->customer->legal_name ?: trim($lockedInvoice->customer->first_name.' '.$lockedInvoice->customer->last_name),
                    'status' => 'posted', 'source_type' => Payment::class, 'source_id' => $payment->id, 'created_by' => $user->id,
                ]);
            }

            $this->receivableService->synchronize($lockedInvoice);

            return $payment->load(['company', 'invoice', 'paymentMethod', 'receivedBy', 'cashSession']);
        });
    }
}
