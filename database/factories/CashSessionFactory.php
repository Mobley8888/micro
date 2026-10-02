<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_register_id' => CashRegister::factory(),
            'company_id' => fn (array $attributes): string => CashRegister::query()->findOrFail($attributes['cash_register_id'])->company_id,
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'opening_amount' => '0.00',
            'status' => CashSession::STATUS_OPEN,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => CashSession::STATUS_CLOSED,
            'closed_by' => User::factory(),
            'closed_at' => now(),
            'closing_amount' => '0.00',
            'expected_amount' => '0.00',
            'difference' => '0.00',
        ]);
    }
}
