<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\Store;
use App\Models\SubOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'sub_order_id' => SubOrder::factory(),
            'amount_cents' => fake()->numberBetween(1000, 50_000),
            'commission_cents' => fake()->numberBetween(100, 5_000),
            'status' => 'pending',
        ];
    }
}
