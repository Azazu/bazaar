<?php

namespace Database\Factories;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_id' => User::factory(),
            'name' => $name,
            'slug' => str($name)->slug(),
            'description' => fake()->sentence(),
            'status' => StoreStatus::Active,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => StoreStatus::Pending]);
    }
}
