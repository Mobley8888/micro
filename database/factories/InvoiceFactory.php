<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'customer_id' => Customer::factory(),
            'number' => fake()->unique()->bothify('FAC-####'),
            'issue_date' => today(),
            'subtotal' => 0,
            'total' => 0,
            'balance_due' => 0,
            'status' => 'draft',
        ];
    }
}
