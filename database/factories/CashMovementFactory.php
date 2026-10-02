<?php

namespace Database\Factories;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_session_id' => CashSession::factory(),
            'cash_register_id' => fn (array $attributes): string => CashSession::query()->findOrFail($attributes['cash_session_id'])->cash_register_id,
            'company_id' => fn (array $attributes): string => CashSession::query()->findOrFail($attributes['cash_session_id'])->company_id,
            'type' => CashMovement::TYPE_DEPOSIT,
            'direction' => CashMovement::DIRECTION_IN,
            'amount' => '100.00',
            'occurred_at' => now(),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function outflow(): static
    {
        return $this->state(fn (): array => [
            'type' => CashMovement::TYPE_WITHDRAWAL,
            'direction' => CashMovement::DIRECTION_OUT,
        ]);
    }
}
