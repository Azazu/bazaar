<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => fake()->unique()->bothify('SKU-####-???'),
            'name' => fake()->randomElement(['S', 'M', 'L', 'XL']).' / '.fake()->safeColorName(),
            'price_cents' => fake()->numberBetween(500, 50_000),
            'stock' => fake()->numberBetween(0, 100),
        ];
    }
}
