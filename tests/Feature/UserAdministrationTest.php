<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_seeded_super_administrator_role_is_recognized_without_reassignment(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $role = Role::query()->create(['company_id' => $company->id, 'name' => 'Super Administrateur', 'slug' => 'super-administrator']);
        $user->roles()->attach($role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->isSuperAdmin());
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk();
        $this->get('/admin/users/'.$user->id)->assertOk()->assertDontSee($user->password);
        $this->assertSame('super-administrator', $user->fresh()->roles()->first()->slug);
    }

    public function test_super_admin_can_manage_companies_and_users(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole($company, User::ROLE_SUPER_ADMIN);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk();
        $this->get('/admin/companies')->assertOk();
        $this->get('/admin/companies/create')->assertOk();
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/users/create')->assertOk();

        $this->post('/admin/companies', [
            'name' => 'Nouvelle structure',
            'legal_name' => 'Nouvelle structure SARL',
            'email' => 'contact@structure.test',
            'phone' => '+242060000000',
            'country' => 'Congo',
            'currency_code' => 'XAF',
        ])->assertRedirect();
        $createdCompany = Company::query()->where('name', 'Nouvelle structure')->firstOrFail();
        $this->assertTrue($createdCompany->is_active);
        $this->get(route('admin.companies.show', $createdCompany))->assertOk();
        $this->get(route('admin.companies.edit', $createdCompany))->assertOk();
        $this->put(route('admin.companies.update', $createdCompany), [
            'name' => 'Structure modifiée',
            'legal_name' => 'Structure modifiée SARL',
            'email' => 'contact@structure.test',
            'phone' => '+242060000000',
            'country' => 'Congo',
            'currency_code' => 'XAF',
        ])->assertRedirect();
        $this->assertSame('Structure modifiée', $createdCompany->fresh()->name);

        $this->post('/admin/users', [
            'name' => 'Gestionnaire entreprise',
            'email' => 'gestionnaire@structure.test',
            'company_id' => $createdCompany->id,
            'role_id' => Role::query()->where('company_id', $createdCompany->id)->where('slug', User::ROLE_COMPANY_ADMIN)->value('id'),
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
            'is_active' => '1',
        ])->assertRedirect();
        $managedUser = User::query()->where('email', 'gestionnaire@structure.test')->firstOrFail();
        $this->assertSame($createdCompany->id, $managedUser->company_id);
        $this->assertTrue($managedUser->hasRole(User::ROLE_COMPANY_ADMIN));
        $this->assertTrue(Hash::check('safe-password-123', $managedUser->password));
        $this->get(route('admin.users.show', $managedUser))->assertOk()->assertDontSee('safe-password-123');

        $this->get(route('admin.users.edit', $managedUser))->assertOk();
        $this->put(route('admin.users.update', $managedUser), [
            'name' => 'Gestionnaire modifié',
            'email' => 'gestionnaire@structure.test',
            'company_id' => $company->id,
            'role_id' => Role::query()->where('company_id', $company->id)->where('slug', User::ROLE_OPERATOR)->value('id'),
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($company->id, $managedUser->fresh()->company_id);
        $this->assertTrue($managedUser->fresh()->hasRole(User::ROLE_OPERATOR));

        $this->patch(route('admin.companies.deactivate', $createdCompany))->assertRedirect();
        $this->assertFalse($createdCompany->fresh()->is_active);
        $this->patch(route('admin.companies.activate', $createdCompany))->assertRedirect();
        $this->assertTrue($createdCompany->fresh()->is_active);
        $this->patch(route('admin.users.deactivate', $managedUser))->assertRedirect();
        $this->assertFalse($managedUser->fresh()->is_active);
        $this->patch(route('admin.users.activate', $managedUser))->assertRedirect();
        $this->assertTrue($managedUser->fresh()->is_active);
    }

    public function test_super_admin_can_configure_stock_policy_for_company(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole($company, User::ROLE_SUPER_ADMIN);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');

        $this->post('/admin/companies', [
            'name' => 'Entreprise stockée',
            'legal_name' => 'Entreprise stockée SARL',
            'email' => 'stock@structure.test',
            'phone' => '+242060000001',
            'country' => 'Congo',
            'currency_code' => 'XAF',
            'valuation_method' => 'CMUP',
            'allow_negative_stock' => '1',
        ])->assertRedirect();

        $createdCompany = Company::query()->where('name', 'Entreprise stockée')->firstOrFail();
        $this->assertSame('CMUP', $createdCompany->valuation_method);
        $this->assertTrue($createdCompany->allow_negative_stock);
    }

    public function test_company_admin_can_only_create_and_manage_operators_in_own_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $admin = $this->userWithRole($companyA, User::ROLE_COMPANY_ADMIN);
        $operatorB = $this->userWithRole($companyB, User::ROLE_OPERATOR);
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);

        $this->get('/admin')->assertOk();
        $this->get('/users')->assertOk();
        $this->get('/admin/companies')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->get(route('users.show', $operatorB))->assertNotFound();
        $this->get(route('users.show', $admin))->assertNotFound();
        $this->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'company_id' => $companyB->id,
            'role' => User::ROLE_SUPER_ADMIN,
        ])->assertNotFound();

        $this->post('/users', [
            'name' => 'Opérateur A',
            'email' => 'operator-a@test.local',
            'company_id' => $companyB->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
            'is_active' => '1',
        ])->assertRedirect();

        $operatorA = User::query()->where('email', 'operator-a@test.local')->firstOrFail();
        $this->assertSame($companyA->id, $operatorA->company_id);
        $this->assertTrue($operatorA->hasRole(User::ROLE_OPERATOR));
        $this->assertFalse($operatorA->isSuperAdmin());

        $this->put(route('users.update', $operatorA), [
            'name' => 'Opérateur modifié',
            'email' => $operatorA->email,
            'company_id' => $companyB->id,
            'role' => User::ROLE_COMPANY_ADMIN,
            'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame($companyA->id, $operatorA->fresh()->company_id);
        $this->assertTrue($operatorA->fresh()->hasRole(User::ROLE_OPERATOR));
        $this->patch(route('users.deactivate', $operatorA))->assertRedirect();
        $this->assertFalse($operatorA->fresh()->is_active);
        $this->patch(route('users.activate', $operatorA))->assertRedirect();
        $this->assertTrue($operatorA->fresh()->is_active);
    }

    public function test_operator_is_denied_administration_but_keeps_commercial_access(): void
    {
        $company = Company::factory()->create();
        $operator = $this->userWithRole($company, User::ROLE_OPERATOR);
        $operator->roles()->firstOrFail()->permissions()->sync(
            collect(['invoice.view', 'invoice.create', 'payment.view', 'payment.create'])
                ->map(fn (string $slug): Permission => Permission::query()->firstOrCreate(
                    ['slug' => $slug],
                    ['name' => ucfirst(str_replace('.', ' ', $slug))],
                ))
                ->pluck('id')
                ->all(),
        );
        $this->post('/login', ['email' => $operator->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($operator);
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/companies')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
        $this->put(route('admin.users.update', $operator), [
            'name' => $operator->name,
            'email' => $operator->email,
            'company_id' => $company->id,
            'role_id' => 1,
        ])->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->post('/users', [])->assertForbidden();
        $this->get('/quotes')->assertForbidden();
        $this->get('/invoices')->assertOk();
        $this->get('/payments')->assertOk();
    }

    public function test_global_super_admin_can_have_no_company_and_company_admin_dashboard_is_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $globalAdmin = User::factory()->create(['company_id' => null]);
        $this->assignRole($globalAdmin, User::ROLE_SUPER_ADMIN, null);
        $this->actingAs($globalAdmin)->get('/admin/companies')->assertOk();

        $companyAdmin = $this->userWithRole($companyA, User::ROLE_COMPANY_ADMIN);
        $this->actingAs($companyAdmin)->get('/admin')
            ->assertOk()
            ->assertSee('<p>Utilisateurs</p><strong>1</strong>', false);
        $this->assertNotSame($companyA->id, $companyB->id);
    }

    public function test_disabled_company_blocks_new_logins_and_existing_sessions(): void
    {
        $company = Company::factory()->create(['is_active' => false]);
        $user = $this->userWithRole($company, User::ROLE_OPERATOR);
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Votre compte est désactivé. Contactez l’administrateur.']);
        $this->actingAs($user)->get('/quotes')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function userWithRole(Company $company, string $slug): User
    {
        $user = User::factory()->for($company)->create();
        $this->assignRole($user, $slug, $slug === User::ROLE_SUPER_ADMIN ? null : $company->id);

        return $user;
    }

    private function assignRole(User $user, string $slug, ?string $companyId): void
    {
        $role = Role::query()->firstOrCreate(
            ['company_id' => $companyId, 'slug' => $slug],
            ['name' => $slug],
        );
        $user->roles()->sync([$role->id]);
    }
}
