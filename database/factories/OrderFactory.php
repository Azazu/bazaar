<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(1000, 100_000);
        $shipping = fake()->randomElement([0, 500, 1000]);
        $discount = 0;

        return [
            'buyer_id' => User::factory(),
            'status' => 'pending',
            'currency' => 'USD',
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $shipping,
            'discount_cents' => $discount,
            'total_cents' => $subtotal + $shipping - $discount,
            'shipping_address' => [
                'name' => fake()->name(),
                'line1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'postcode' => fake()->postcode(),
                'country' => fake()->countryCode(),
            ],
            'shipping_method' => 'standard',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid']);
    }
}
