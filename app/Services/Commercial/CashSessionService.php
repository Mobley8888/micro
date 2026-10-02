<?php

namespace App\Services\Commercial;

use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use App\Support\CashAmount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashSessionService
{
    public function __construct(private readonly CashMovementService $movementService) {}

    public function open(User $user, CashRegister $cashRegister, string|int $openingAmount): CashSession
    {
        $this->authorize($user, 'cash.open');
        abort_unless($user->isSuperAdmin() || $cashRegister->company_id === $user->company_id, 404);

        return DB::transaction(function () use ($user, $cashRegister, $openingAmount): CashSession {
            $register = CashRegister::query()
                ->whereKey($cashRegister->getKey())
                ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $user->company_id))
                ->lockForUpdate()
                ->firstOrFail();

            if (! $register->is_active || ! $register->company()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['cash_register' => 'Cette caisse ou son entreprise est inactive.']);
            }

            if ($register->sessions()->where('status', CashSession::STATUS_OPEN)->exists()) {
                throw ValidationException::withMessages(['cash_register' => 'Cette caisse possède déjà une session ouverte.']);
            }

            if (CashAmount::toCents((string) $openingAmount) < 0) {
                throw ValidationException::withMessages(['opening_amount' => 'Le fonds d’ouverture doit être positif ou nul.']);
            }

            $session = $register->sessions()->create([
                'company_id' => $register->company_id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_amount' => $openingAmount,
                'status' => CashSession::STATUS_OPEN,
            ]);

            $this->movementService->recordOpeningMovement($session, $user);

            return $session->load(['cashRegister', 'openedBy']);
        }, attempts: 3);
    }

    public function close(User $user, CashSession $cashSession, string|int $closingAmount, ?string $closingNote): CashSession
    {
        $this->authorize($user, 'cash.close');

        return DB::transaction(function () use ($user, $cashSession, $closingAmount, $closingNote): CashSession {
            $session = $this->movementService->lockAccessibleSession($user, $cashSession);
            $this->movementService->ensureOpen($session);

            $snapshot = $this->snapshot($session);
            $countedCents = CashAmount::toCents((string) $closingAmount);
            $differenceCents = $countedCents - CashAmount::toCents($snapshot['expected_amount']);

            if ($differenceCents !== 0 && blank($closingNote)) {
                throw ValidationException::withMessages(['closing_note' => 'Une note est obligatoire lorsque le montant compté présente un écart.']);
            }

            $session->update([
                'status' => CashSession::STATUS_CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_amount' => CashAmount::fromCents($countedCents),
                'expected_amount' => $snapshot['expected_amount'],
                'difference' => CashAmount::fromCents($differenceCents),
                'closing_note' => $closingNote,
            ]);

            return $session->refresh()->load(['cashRegister', 'openedBy', 'closedBy']);
        }, attempts: 3);
    }

    /** @return array{opening_amount:string,total_in:string,total_out:string,expected_amount:string,movement_count:int,last_movement:?CashMovement} */
    public function snapshot(CashSession $cashSession): array
    {
        $session = $cashSession->loadMissing('movements');
        $totalInCents = 0;
        $totalOutCents = 0;

        $session->movements()
            ->where('type', '!=', CashMovement::TYPE_OPENING)
            ->get(['amount', 'direction'])
            ->each(function (CashMovement $movement) use (&$totalInCents, &$totalOutCents): void {
                if ($movement->direction === CashMovement::DIRECTION_IN) {
                    $totalInCents += CashAmount::toCents($movement->amount);
                } elseif ($movement->direction === CashMovement::DIRECTION_OUT) {
                    $totalOutCents += CashAmount::toCents($movement->amount);
                }
            });

        $openingCents = CashAmount::toCents($session->opening_amount);
        $expectedCents = $openingCents + $totalInCents - $totalOutCents;

        return [
            'opening_amount' => CashAmount::fromCents($openingCents),
            'total_in' => CashAmount::fromCents($totalInCents),
            'total_out' => CashAmount::fromCents($totalOutCents),
            'expected_amount' => CashAmount::fromCents($expectedCents),
            'movement_count' => $session->movements()->count(),
            'last_movement' => $session->movements()->with('createdBy')->latest('occurred_at')->first(),
        ];
    }

    public function movements(CashSession $cashSession): LengthAwarePaginator
    {
        return $cashSession->movements()
            ->select('*')
            ->selectRaw(
                'SUM(CASE WHEN direction = ? THEN amount ELSE -amount END) OVER (ORDER BY occurred_at, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS balance_after',
                [CashMovement::DIRECTION_IN],
            )
            ->with('createdBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    private function authorize(User $user, string $permission): void
    {
        abort_unless($user->hasPermission($permission), 403);
    }
}
