<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreCashRegisterRequest;
use App\Http\Requests\Commercial\UpdateCashRegisterRequest;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Services\Commercial\CashRegisterService;
use App\Services\Commercial\CashSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function index(Request $request, CashRegisterService $cashRegisterService): View
    {
        $registers = $cashRegisterService->index($request->user());

        return view('cash-registers.index', compact('registers'));
    }

    public function create(Request $request): View
    {
        $companies = $request->user()->isSuperAdmin()
            ? Company::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'legal_name'])
            : collect();

        return view('cash-registers.form', ['cashRegister' => new CashRegister, 'companies' => $companies]);
    }

    public function store(StoreCashRegisterRequest $request, CashRegisterService $cashRegisterService): RedirectResponse
    {
        $cashRegister = $cashRegisterService->store($request->user(), $request->validated());

        return redirect()->route('cash-registers.show', $cashRegister)->with('success', 'Caisse créée.');
    }

    public function show(
        Request $request,
        CashRegister $cashRegister,
        CashRegisterService $cashRegisterService,
        CashSessionService $cashSessionService,
    ): View {
        $cashRegister = $cashRegisterService->find($request->user(), $cashRegister);
        $openSession = $cashRegister->sessions()
            ->where('status', CashSession::STATUS_OPEN)
            ->with(['openedBy', 'movements'])
            ->first();
        $snapshot = $openSession ? $cashSessionService->snapshot($openSession) : null;
        $movements = $openSession ? $cashSessionService->movements($openSession) : null;
        $sessions = $cashRegister->sessions()
            ->with(['openedBy', 'closedBy'])
            ->latest('opened_at')
            ->paginate(10);

        return view('cash-registers.show', compact('cashRegister', 'openSession', 'snapshot', 'movements', 'sessions'));
    }

    public function edit(Request $request, CashRegister $cashRegister, CashRegisterService $cashRegisterService): View
    {
        $cashRegisterService->find($request->user(), $cashRegister);

        return view('cash-registers.form', ['cashRegister' => $cashRegister, 'companies' => collect()]);
    }

    public function update(
        UpdateCashRegisterRequest $request,
        CashRegister $cashRegister,
        CashRegisterService $cashRegisterService,
    ): RedirectResponse {
        $cashRegister = $cashRegisterService->update($request->user(), $cashRegister, $request->validated());

        return redirect()->route('cash-registers.show', $cashRegister)->with('success', 'Caisse mise à jour.');
    }

    public function activate(Request $request, CashRegister $cashRegister, CashRegisterService $cashRegisterService): RedirectResponse
    {
        $cashRegisterService->setActive($request->user(), $cashRegister, true);

        return back()->with('success', 'Caisse activée.');
    }

    public function deactivate(Request $request, CashRegister $cashRegister, CashRegisterService $cashRegisterService): RedirectResponse
    {
        $cashRegisterService->setActive($request->user(), $cashRegister, false);

        return back()->with('success', 'Caisse désactivée.');
    }
}
