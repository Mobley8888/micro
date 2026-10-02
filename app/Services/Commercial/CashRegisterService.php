<?php

namespace App\Services\Commercial;

use App\Models\CashRegister;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    public function index(User $user): LengthAwarePaginator
    {
        return CashRegister::query()
            ->with(['company', 'sessions' => fn ($query) => $query->where('status', 'open')])
            ->when(! $user->isSuperAdmin(), fn ($query) => $query->where('company_id', $user->company_id))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();
    }

    public function store(User $user, array $attributes): CashRegister
    {
        $this->authorize($user, 'cash.create');
        $companyId = $user->isSuperAdmin() ? $attributes['company_id'] : $user->company_id;
        abort_unless($companyId !== null, 404);

        $company = Company::query()->whereKey($companyId)->where('is_active', true)->firstOrFail();

        return DB::transaction(fn (): CashRegister => $company->cashRegisters()->create([
            'name' => $attributes['name'],
            'code' => $attributes['code'],
            'description' => $attributes['description'] ?? null,
            'is_active' => $attributes['is_active'] ?? true,
        ]));
    }

    public function update(User $user, CashRegister $cashRegister, array $attributes): CashRegister
    {
        $this->authorize($user, 'cash.create');
        $this->assertAccessible($user, $cashRegister);

        return DB::transaction(function () use ($cashRegister, $attributes): CashRegister {
            $locked = CashRegister::query()->whereKey($cashRegister->getKey())->lockForUpdate()->firstOrFail();
            if (! $attributes['is_active'] && $locked->sessions()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Clôturez la session ouverte avant de désactiver cette caisse.']);
            }

            $locked->update([
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'description' => $attributes['description'] ?? null,
                'is_active' => $attributes['is_active'],
            ]);

            return $locked->refresh();
        });
    }

    public function setActive(User $user, CashRegister $cashRegister, bool $isActive): CashRegister
    {
        $this->authorize($user, 'cash.create');
        $this->assertAccessible($user, $cashRegister);

        return DB::transaction(function () use ($cashRegister, $isActive): CashRegister {
            $locked = CashRegister::query()->whereKey($cashRegister->getKey())->lockForUpdate()->firstOrFail();
            if (! $isActive && $locked->sessions()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['cash_register' => 'Clôturez la session ouverte avant de désactiver cette caisse.']);
            }

            $locked->update(['is_active' => $isActive]);

            return $locked->refresh();
        });
    }

    public function find(User $user, CashRegister $cashRegister): CashRegister
    {
        $this->authorize($user, 'cash.view');
        $this->assertAccessible($user, $cashRegister);

        return $cashRegister->load('company');
    }

    public function assertAccessible(User $user, CashRegister $cashRegister): void
    {
        abort_unless($user->isSuperAdmin() || $cashRegister->company_id === $user->company_id, 404);
    }

    private function authorize(User $user, string $permission): void
    {
        abort_unless($user->hasPermission($permission), 403);
    }
}
