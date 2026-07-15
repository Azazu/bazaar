<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     *
     * The reviewable is set by the caller, e.g.
     *   Review::factory()->for($product, 'reviewable')->create()
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->sentence(),
            'approved' => true,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['approved' => false]);
    }
}
