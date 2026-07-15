<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
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
        $title = fake()->unique()->words(3, true);

        return [
            'title' => ucfirst($title),
            'slug' => str($title)->slug(),
            'description' => fake()->paragraph(),
            'price_cents' => fake()->numberBetween(500, 50_000),
            'currency' => 'USD',
            'status' => ProductStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Draft]);
    }
}
