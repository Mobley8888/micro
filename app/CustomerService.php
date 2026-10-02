<?php

namespace App;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;

class CustomerService
{
    public function create(array $attributes): Customer
    {
        $companyId = $this->currentCompanyId();
        $attributes['company_id'] = $companyId;
        $attributes['code'] ??= $this->nextCode($companyId);
        $attributes['status'] ??= 'active';

        return Customer::create($attributes);
    }

    public function update(Customer $customer, array $attributes): Customer
    {
        abort_unless($customer->company_id === $this->currentCompanyId(), 404);
        $customer->update($attributes);

        return $customer->refresh();
    }

    private function nextCode(string $companyId): string
    {
        $lastCode = Customer::query()->where('company_id', $companyId)->withTrashed()->latest('created_at')->value('code');
        $sequence = $lastCode !== null && preg_match('/(\d+)$/', $lastCode, $matches) === 1 ? ((int) $matches[1]) + 1 : 1;

        return 'CLI-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function currentCompanyId(): string
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);
        abort_unless($user->company_id !== null, 404);

        return (string) $user->company_id;
    }
}
