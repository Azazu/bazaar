<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'product_title' => ucfirst(fake()->words(3, true)),
            'variant_name' => fake()->randomElement(['S', 'M', 'L', 'XL']).' / '.fake()->safeColorName(),
            'unit_price_cents' => fake()->numberBetween(500, 50_000),
            'qty' => fake()->numberBetween(1, 3),
        ];
    }
}
