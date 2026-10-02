<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_rejects_a_short_password_and_a_mismatched_confirmation(): void
    {
        [$company, $role, $admin] = $this->preparedAdministration();
        $this->actingAs($admin);

        $this->post(route('admin.users.store'), $this->creationData($company, $role, [
            'email' => 'short-password@test.local',
            'password' => 'short7!',
            'password_confirmation' => 'short7!',
        ]))->assertSessionHasErrors([
            'password' => 'Le champ mot de passe doit contenir au moins 8 caractères.',
        ]);

        $this->post(route('admin.users.store'), $this->creationData($company, $role, [
            'email' => 'mismatched-password@test.local',
            'password' => 'MicroTest@2026!',
            'password_confirmation' => 'Different@2026!',
        ]))->assertSessionHasErrors([
            'password' => 'La confirmation du champ mot de passe ne correspond pas.',
        ]);
    }

    public function test_modification_keeps_a_blank_password_and_accepts_a_valid_password_change(): void
    {
        [$company, $role, $admin] = $this->preparedAdministration();
        $managedUser = User::factory()->for($company)->create();
        $managedUser->roles()->attach($role);
        $this->actingAs($admin);

        $this->put(route('admin.users.update', $managedUser), $this->updateData($company, $role, [
            'password' => '',
            'password_confirmation' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('password', $managedUser->fresh()->password));

        $this->put(route('admin.users.update', $managedUser), $this->updateData($company, $role, [
            'password' => 'MicroTest@2026!',
            'password_confirmation' => 'MicroTest@2026!',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('MicroTest@2026!', $managedUser->fresh()->password));

        $this->put(route('admin.users.update', $managedUser), $this->updateData($company, $role, [
            'password' => 'short7!',
            'password_confirmation' => 'short7!',
        ]))->assertSessionHasErrors([
            'password' => 'Le champ mot de passe doit contenir au moins 8 caractères.',
        ]);
        $this->assertTrue(Hash::check('MicroTest@2026!', $managedUser->fresh()->password));
    }

    public function test_creation_rejects_unknown_roles_and_companies(): void
    {
        [$company, $role, $admin] = $this->preparedAdministration();
        $this->actingAs($admin);

        $this->post(route('admin.users.store'), $this->creationData($company, $role, [
            'email' => 'unknown-role@test.local',
            'role_id' => 999999,
        ]))->assertSessionHasErrors('role_id');

        $this->post(route('admin.users.store'), $this->creationData($company, $role, [
            'email' => 'unknown-company@test.local',
            'company_id' => '00000000-0000-0000-0000-000000000000',
        ]))->assertNotFound();
    }

    /**
     * @return array{Company, Role, User}
     */
    private function preparedAdministration(): array
    {
        $company = Company::factory()->create();
        app(RoleCatalogService::class)->ensureCompanyRoles($company);
        $role = Role::query()->where('company_id', $company->id)->where('slug', User::ROLE_OPERATOR)->firstOrFail();
        $admin = User::factory()->for($company)->create();
        $superAdminRole = Role::query()->firstOrCreate(
            ['company_id' => null, 'slug' => User::ROLE_SUPER_ADMIN],
            ['name' => 'Super administrateur'],
        );
        $admin->roles()->attach($superAdminRole);

        return [$company, $role, $admin];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function creationData(Company $company, Role $role, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Test Opérateur',
            'email' => 'operator-create@test.local',
            'phone' => '+242060000000',
            'company_id' => $company->id,
            'role_id' => $role->id,
            'password' => 'MicroTest@2026!',
            'password_confirmation' => 'MicroTest@2026!',
            'is_active' => '1',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updateData(Company $company, Role $role, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Utilisateur modifié',
            'email' => 'operator-edit@test.local',
            'phone' => '+242060000000',
            'company_id' => $company->id,
            'role_id' => $role->id,
            'is_active' => '1',
        ], $overrides);
    }
}
