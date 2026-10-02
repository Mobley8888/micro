<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
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
            'type' => 'service',
            'code' => fake()->unique()->bothify('SRV-#####'),
            'name' => fake()->sentence(3),
            'sale_price' => fake()->randomFloat(2, 10000, 500000),
            'is_active' => true,
        ];
    }
}
