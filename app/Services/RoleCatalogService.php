<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RoleCatalogService
{
    public function ensureCompanyRoles(Company $company): void
    {
        foreach ([
            'company_admin' => 'Administrateur entreprise',
            'operator' => 'Opérateur',
        ] as $slug => $name) {
            $role = Role::query()->firstOrCreate(
                ['company_id' => $company->id, 'slug' => $slug],
                ['name' => $name],
            );

            $workspacePermissions = $slug === 'company_admin'
                ? ['dashboard.view', 'customer.view', 'customer.create', 'invoice.view', 'invoice.create', 'payment.view', 'payment.create', 'receipt.view', 'cash.view', 'cash.create', 'cash.open', 'cash.move', 'cash.close', 'expense.view', 'expense.create', 'expense.cancel', 'cash.expense', 'cash.outflow', 'bank.view', 'bank.create', 'bank.transaction', 'bank.reconcile', 'financial.journal.view', 'financial.report.view', 'stock.view', 'stock.create', 'stock.adjust', 'stock.transfer', 'stock.inventory', 'stock.validate', 'stock.valuation.view']
                : ['dashboard.view', 'customer.view', 'customer.create', 'invoice.view', 'invoice.create', 'payment.view', 'payment.create', 'receipt.view', 'cash.view', 'cash.open', 'cash.move', 'cash.close', 'expense.view', 'cash.expense', 'cash.outflow', 'stock.view'];

            $permissionIds = Permission::query()->whereIn('slug', $workspacePermissions)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    /** @return Collection<int, Role> */
    public function rolesForCompany(Company $company): Collection
    {
        $this->ensureCompanyRoles($company);

        return Role::query()->where('company_id', $company->id)
            ->whereIn('slug', ['company_admin', 'operator'])->orderBy('name')->get();
    }
}
