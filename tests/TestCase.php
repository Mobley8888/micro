<?php

namespace Tests;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  list<string>  $permissions
     */
    protected function createUserWithPermissions(Company $company, array $permissions): User
    {
        $user = User::factory()->for($company)->create();
        $role = Role::query()->create([
            'company_id' => $company->id,
            'name' => 'Test role',
            'slug' => 'test-'.$user->getKey().'-'.Str::lower(Str::random(8)),
        ]);

        $permissionIds = collect($permissions)
            ->map(fn (string $slug): Permission => Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => Str::headline($slug)],
            ))
            ->pluck('id')
            ->all();

        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role);

        return $user;
    }
}
