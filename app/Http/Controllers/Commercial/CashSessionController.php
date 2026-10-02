<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\CloseCashSessionRequest;
use App\Http\Requests\Commercial\OpenCashSessionRequest;
use App\Http\Requests\Commercial\StoreCashMovementRequest;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\Commercial\CashMovementService;
use App\Services\Commercial\CashSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CashSessionController extends Controller
{
    public function open(
        OpenCashSessionRequest $request,
        CashRegister $cashRegister,
        CashSessionService $cashSessionService,
    ): RedirectResponse {
        $cashSessionService->open($request->user(), $cashRegister, $request->validated('opening_amount'));

        return redirect()->route('cash-registers.show', $cashRegister)->with('success', 'Session de caisse ouverte.');
    }

    public function movement(
        StoreCashMovementRequest $request,
        CashRegister $cashRegister,
        CashSession $cashSession,
        CashMovementService $cashMovementService,
    ): RedirectResponse {
        abort_unless($cashSession->cash_register_id === $cashRegister->id, 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->isCompanyAdmin() || $cashSession->opened_by === $request->user()->id, 404);
        $data = $request->validated();
        $attachment = $data['attachment'] ?? null;
        unset($data['attachment']);
        $attachmentPath = $attachment?->store('cash-movements/'.$cashSession->company_id, 'local');
        if ($attachmentPath !== null) {
            $data['attachment_path'] = $attachmentPath;
        }

        try {
            $movement = $cashMovementService->recordManualMovement($request->user(), $cashSession, $data);
            if ($attachmentPath !== null && ($movement->metadata['attachment_path'] ?? null) !== $attachmentPath) {
                Storage::disk('local')->delete($attachmentPath);
            }
        } catch (Throwable $exception) {
            if ($attachmentPath !== null) {
                Storage::disk('local')->delete($attachmentPath);
            }

            throw $exception;
        }

        return redirect()->route('cash-registers.show', $cashRegister)->with('success', 'Mouvement enregistré.');
    }

    public function attachment(Request $request, CashMovement $cashMovement): StreamedResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $cashMovement->company_id === $request->user()->company_id, 404);
        abort_unless(is_string($cashMovement->metadata['attachment_path'] ?? null), 404);
        $path = $cashMovement->metadata['attachment_path'];
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    public function close(
        CloseCashSessionRequest $request,
        CashRegister $cashRegister,
        CashSession $cashSession,
        CashSessionService $cashSessionService,
    ): RedirectResponse {
        abort_unless($cashSession->cash_register_id === $cashRegister->id, 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->isCompanyAdmin() || $cashSession->opened_by === $request->user()->id, 404);
        $cashSessionService->close(
            $request->user(),
            $cashSession,
            $request->validated('closing_amount'),
            $request->validated('closing_note'),
        );

        return redirect()->route('cash-registers.show', $cashRegister)->with('success', 'Session clôturée.');
    }
}
