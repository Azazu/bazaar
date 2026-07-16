<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Store;
use App\Models\SubOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubOrder>
 */
class SubOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'store_id' => Store::factory(),
            'status' => 'pending',
            'subtotal_cents' => fake()->numberBetween(1000, 50_000),
        ];
    }
}
