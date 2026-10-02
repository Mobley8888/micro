<?php

namespace App\Services\Commercial;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use App\Support\CashAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashMovementService
{
    public function recordManualMovement(User $user, CashSession $cashSession, array $attributes): CashMovement
    {
        $this->authorize($user, 'cash.move');

        return DB::transaction(function () use ($user, $cashSession, $attributes): CashMovement {
            Company::query()->whereKey($cashSession->company_id)->lockForUpdate()->firstOrFail();
            $session = $this->lockAccessibleSession($user, $cashSession);
            if (! empty($attributes['idempotency_key'])) {
                $existing = CashMovement::query()->where('company_id', $session->company_id)->where('idempotency_key', $attributes['idempotency_key'])->first();
                if ($existing !== null) {
                    abort_unless($existing->cash_session_id === $session->id, 409, 'Cette clé est déjà utilisée pour un autre mouvement.');

                    return $existing;
                }
            }
            $this->ensureOpen($session);

            [$direction, $type] = match ($attributes['type']) {
                'deposit' => [CashMovement::DIRECTION_IN, CashMovement::TYPE_DEPOSIT],
                'withdrawal' => [CashMovement::DIRECTION_OUT, CashMovement::TYPE_WITHDRAWAL],
                default => throw ValidationException::withMessages(['type' => 'Le type de mouvement est invalide.']),
            };
            if ($direction === CashMovement::DIRECTION_OUT
                && CashAmount::toCents($session->opening_amount) + $session->movements()->where('direction', CashMovement::DIRECTION_IN)->where('type', '!=', CashMovement::TYPE_OPENING)->get(['amount'])->sum(fn (CashMovement $movement): int => CashAmount::toCents($movement->amount)) - $session->movements()->where('direction', CashMovement::DIRECTION_OUT)->get(['amount'])->sum(fn (CashMovement $movement): int => CashAmount::toCents($movement->amount)) < CashAmount::toCents((string) $attributes['amount'])) {
                throw ValidationException::withMessages(['amount' => 'Le solde disponible en caisse est insuffisant.']);
            }

            return $session->movements()->create([
                'company_id' => $session->company_id,
                'cash_register_id' => $session->cash_register_id,
                'type' => $type,
                'direction' => $direction,
                'amount' => $attributes['amount'],
                'occurred_at' => $attributes['occurred_at'] ?? now(),
                'description' => $attributes['description'],
                'reference' => $attributes['reference'] ?? null,
                'created_by' => $user->id,
                'idempotency_key' => $attributes['idempotency_key'] ?? null,
                'metadata' => array_filter([
                    'beneficiary' => $attributes['beneficiary'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                    'attachment_path' => $attributes['attachment_path'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);
        });
    }

    public function recordOpeningMovement(CashSession $session, User $user): CashMovement
    {
        return CashMovement::query()->firstOrCreate(
            [
                'company_id' => $session->company_id,
                'source_type' => CashSession::class,
                'source_id' => $session->id,
            ],
            [
                'cash_register_id' => $session->cash_register_id,
                'cash_session_id' => $session->id,
                'type' => CashMovement::TYPE_OPENING,
                'direction' => CashMovement::DIRECTION_IN,
                'amount' => $session->opening_amount,
                'occurred_at' => $session->opened_at,
                'description' => 'Fonds d’ouverture de caisse',
                'reference' => null,
                'created_by' => $user->id,
            ],
        );
    }

    public function recordPaymentMovement(Payment $payment, CashSession $cashSession, User $user): CashMovement
    {
        $this->authorize($user, 'cash.move');

        return DB::transaction(function () use ($payment, $cashSession, $user): CashMovement {
            $session = CashSession::query()
                ->whereKey($cashSession->getKey())
                ->where('company_id', $payment->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($user->isSuperAdmin() || $session->company_id === $user->company_id, 404);

            $existing = CashMovement::query()
                ->where('company_id', $payment->company_id)
                ->where('source_type', Payment::class)
                ->where('source_id', $payment->id)
                ->first();

            if ($existing !== null) {
                abort_unless($existing->cash_session_id === $session->id, 409, 'Ce paiement est déjà rattaché à une autre session de caisse.');

                return $existing;
            }

            $this->ensureOpen($session);
            abort_unless($payment->cash_session_id === $session->id && $payment->status === 'posted', 404);

            if (CashAmount::toCents($payment->amount) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Le montant du paiement doit être supérieur à zéro.']);
            }

            return $session->movements()->create([
                'company_id' => $payment->company_id,
                'cash_register_id' => $session->cash_register_id,
                'type' => CashMovement::TYPE_PAYMENT,
                'direction' => CashMovement::DIRECTION_IN,
                'amount' => $payment->amount,
                'occurred_at' => $payment->paid_at,
                'description' => 'Paiement espèces de la facture '.$payment->invoice->number,
                'reference' => $payment->number,
                'source_type' => Payment::class,
                'source_id' => $payment->id,
                'created_by' => $user->id,
                'metadata' => ['invoice_id' => $payment->invoice_id],
            ]);
        });
    }

    public function lockAccessibleSession(User $user, CashSession $cashSession): CashSession
    {
        $query = CashSession::query()->whereKey($cashSession->getKey());
        if (! $user->isSuperAdmin()) {
            abort_unless($user->company_id !== null, 404);
            $query->where('company_id', $user->company_id);
        }

        return $query->lockForUpdate()->firstOrFail();
    }

    public function ensureOpen(CashSession $cashSession): void
    {
        if ($cashSession->status !== CashSession::STATUS_OPEN) {
            throw ValidationException::withMessages(['session' => 'Cette session est clôturée et ne peut plus recevoir de mouvement.']);
        }
    }

    private function authorize(User $user, string $permission): void
    {
        abort_unless($user->hasPermission($permission), 403);
    }
}
