<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserAdministrationController extends Controller
{
    public function index(Request $request): View
    {
        $users = $this->userQuery($request)->with(['company', 'roles'])->orderBy('name')->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create(Request $request, RoleCatalogService $roleCatalog): View
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->isCompanyAdmin(), 403);
        $companyId = $request->user()->isSuperAdmin() ? $request->query('company_id') : $request->user()->company_id;
        $companies = $request->user()->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect();
        $selectedCompany = Company::query()->whereKey($companyId)->where('is_active', true)->first();
        if ($request->user()->isSuperAdmin()) {
            foreach ($companies as $company) {
                $roleCatalog->ensureCompanyRoles($company);
            }
            $roles = Role::query()->with('company')->whereIn('company_id', $companies->pluck('id'))
                ->whereIn('slug', [User::ROLE_COMPANY_ADMIN, User::ROLE_OPERATOR])->orderBy('slug')->get();
        } else {
            $roles = $selectedCompany ? $roleCatalog->rolesForCompany($selectedCompany) : collect();
        }

        return view('admin.users.form', [
            'user' => new User(['company_id' => $selectedCompany?->id]),
            'companies' => $companies,
            'roles' => $roles,
            'isCompanyScoped' => ! $request->user()->isSuperAdmin(),
        ]);
    }

    public function store(Request $request, RoleCatalogService $roleCatalog): RedirectResponse
    {
        $actor = $request->user();
        $isSuperAdmin = $actor->isSuperAdmin();
        abort_unless($isSuperAdmin || $actor->isCompanyAdmin(), 403);
        $companyId = $isSuperAdmin ? (string) $request->input('company_id') : $actor->company_id;
        $company = Company::query()->whereKey($companyId)->where('is_active', true)->firstOrFail();
        $roleCatalog->ensureCompanyRoles($company);
        $data = $this->validatedAttributes($request, $isSuperAdmin, $companyId);
        $role = $isSuperAdmin
            ? Role::query()->whereKey($data['role_id'])->where('company_id', $companyId)->whereIn('slug', [User::ROLE_COMPANY_ADMIN, User::ROLE_OPERATOR])->firstOrFail()
            : Role::query()->where('company_id', $companyId)->where('slug', User::ROLE_OPERATOR)->firstOrFail();
        $user = User::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'],
        ]);
        $user->roles()->sync([$role->id]);

        return redirect()->route($isSuperAdmin ? 'admin.users.show' : 'users.show', $user)->with('success', 'Utilisateur créé.');
    }

    public function show(Request $request, User $user): View
    {
        $this->assertTargetIsManageable($request, $user);

        return view('admin.users.show', compact('user'));
    }

    public function edit(Request $request, User $user, RoleCatalogService $roleCatalog): View
    {
        $this->assertTargetIsManageable($request, $user);
        $user->load('roles');
        $companies = $request->user()->isSuperAdmin() ? Company::query()->where('is_active', true)->orderBy('name')->get() : collect();
        $selectedCompany = Company::query()->whereKey($user->company_id)->where('is_active', true)->first();
        if ($request->user()->isSuperAdmin()) {
            foreach ($companies as $company) {
                $roleCatalog->ensureCompanyRoles($company);
            }
            $roles = Role::query()->with('company')->whereIn('company_id', $companies->pluck('id'))
                ->whereIn('slug', [User::ROLE_COMPANY_ADMIN, User::ROLE_OPERATOR])->orderBy('slug')->get();
        } else {
            $roles = $selectedCompany ? $roleCatalog->rolesForCompany($selectedCompany) : collect();
        }

        return view('admin.users.form', [
            'user' => $user,
            'companies' => $companies,
            'roles' => $roles,
            'isCompanyScoped' => ! $request->user()->isSuperAdmin(),
        ]);
    }

    public function update(Request $request, User $user, RoleCatalogService $roleCatalog): RedirectResponse
    {
        $actor = $request->user();
        $isSuperAdmin = $actor->isSuperAdmin();
        $this->assertTargetIsManageable($request, $user);
        $companyId = $isSuperAdmin ? ($user->isSuperAdmin() ? $user->company_id : (string) $request->input('company_id', $user->company_id)) : $actor->company_id;
        $company = Company::query()->whereKey($companyId)->where('is_active', true)->firstOrFail();
        $roleCatalog->ensureCompanyRoles($company);
        $data = $this->validatedAttributes($request, $isSuperAdmin, $companyId, $user);
        abort_if($request->user()->is($user) && ! $data['is_active'], 403, 'Vous ne pouvez pas désactiver votre propre compte.');
        $attributes = [
            'company_id' => $companyId,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'],
        ];
        if (filled($data['password'] ?? null)) {
            $attributes['password'] = Hash::make($data['password']);
        }
        $user->update($attributes);
        if ($isSuperAdmin && isset($data['role_id'])) {
            $role = Role::query()->whereKey($data['role_id'])->where('company_id', $companyId)
                ->whereIn('slug', [User::ROLE_COMPANY_ADMIN, User::ROLE_OPERATOR])->firstOrFail();
            $user->roles()->sync([$role->id]);
        } elseif (! $isSuperAdmin) {
            $role = Role::query()->where('company_id', $companyId)->where('slug', User::ROLE_OPERATOR)->firstOrFail();
            $user->roles()->sync([$role->id]);
        }

        return redirect()->route($isSuperAdmin ? 'admin.users.show' : 'users.show', $user)->with('success', 'Utilisateur mis à jour.');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->assertTargetIsManageable($request, $user);
        $user->update(['is_active' => true]);

        return back()->with('success', 'Compte activé.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()->is($user), 403, 'Vous ne pouvez pas désactiver votre propre compte.');
        $this->assertTargetIsManageable($request, $user);
        $this->preventLastSuperAdminDeactivation($user);
        $user->update(['is_active' => false]);

        return back()->with('success', 'Compte désactivé. Ses données sont conservées.');
    }

    /** @return Builder<User> */
    private function userQuery(Request $request): Builder
    {
        $query = User::query();
        if ($request->user()->isCompanyAdmin()) {
            $query->where('company_id', $request->user()->company_id)
                ->whereHas('roles', fn (Builder $roles): Builder => $roles
                    ->where('slug', User::ROLE_OPERATOR)
                    ->where('company_id', $request->user()->company_id));
        }

        return $query;
    }

    private function assertTargetIsManageable(Request $request, User $user): void
    {
        abort_unless($this->userQuery($request)->whereKey($user->getKey())->exists(), 404);
    }

    private function preventLastSuperAdminDeactivation(User $user): void
    {
        if (! $user->isSuperAdmin()) {
            return;
        }
        $activeCount = User::query()->where('is_active', true)->get()
            ->filter(fn (User $candidate): bool => $candidate->isSuperAdmin())->count();
        abort_if($activeCount <= 1 && $user->is_active, 422, 'Le dernier administrateur global actif ne peut pas être désactivé.');
    }

    /** @return array<string, mixed> */
    private function validatedAttributes(Request $request, bool $isSuperAdmin, string $companyId, ?User $user = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($isSuperAdmin && ! $user?->isSuperAdmin()) {
            $rules['company_id'] = ['required', 'uuid', Rule::exists('companies', 'id')->where('is_active', true)];
            $rules['role_id'] = [$user === null ? 'required' : 'sometimes', 'integer', Rule::exists('roles', 'id')->where('company_id', $companyId)->whereIn('slug', [User::ROLE_COMPANY_ADMIN, User::ROLE_OPERATOR])];
        }
        $data = $request->validate($rules);
        $data['is_active'] = $request->boolean('is_active', $user?->is_active ?? true);

        return $data;
    }
}
