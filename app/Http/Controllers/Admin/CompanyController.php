<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\RoleCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(): View
    {
        return view('admin.companies.index', ['companies' => Company::query()->withCount('users')->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        return view('admin.companies.form', ['company' => new Company]);
    }

    public function store(Request $request, RoleCatalogService $roleCatalog): RedirectResponse
    {
        $company = Company::query()->create($this->validatedAttributes($request));
        $roleCatalog->ensureCompanyRoles($company);

        return redirect()->route('admin.companies.show', $company)->with('success', 'Entreprise créée.');
    }

    public function show(Company $company): View
    {
        return view('admin.companies.show', ['company' => $company->loadCount('users')]);
    }

    public function edit(Company $company): View
    {
        return view('admin.companies.form', compact('company'));
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $company->update($this->validatedAttributes($request));

        return redirect()->route('admin.companies.show', $company)->with('success', 'Entreprise mise à jour.');
    }

    public function activate(Company $company): RedirectResponse
    {
        $company->update(['is_active' => true]);

        return back()->with('success', 'Entreprise activée.');
    }

    public function deactivate(Company $company): RedirectResponse
    {
        $company->update(['is_active' => false]);

        return back()->with('success', 'Entreprise désactivée. Ses données sont conservées.');
    }

    /** @return array<string, mixed> */
    private function validatedAttributes(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3'],
            'valuation_method' => ['sometimes', 'string', 'in:FIFO,CMUP'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
        ]);
    }
}
