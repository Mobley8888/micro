<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $candidate = User::query()->where('email', $credentials['email'])->first();
        if ($candidate && Hash::check($credentials['password'], $candidate->password)) {
            $disabledCompany = ! $candidate->isSuperAdmin() && (! $candidate->company_id || ! $candidate->company?->is_active);
            if (! $candidate->is_active || $disabledCompany) {
                return back()->withErrors(['email' => 'Votre compte est désactivé. Contactez l’administrateur.'])->onlyInput('email');
            }
        }
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            return back()->withErrors(['email' => 'Identifiants incorrects.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $user = $request->user();

        return redirect()->route($user->isSuperAdmin() || $user->isCompanyAdmin() ? 'admin.dashboard' : 'dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
