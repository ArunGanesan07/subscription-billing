<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'code' => fake()->unique()->slug(2),
            'name' => fake()->randomElement(['Starter', 'Growth', 'Scale']),
            'billing_interval' => BillingInterval::Monthly,
            'base_price' => 300000,          // ₹3,000.00
            'included_units' => 3000,
            'overage_rate' => '50.000000',   // ₹0.50 per unit
            'is_active' => true,
        ];
    }
}
