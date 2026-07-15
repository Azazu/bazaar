<?php

namespace Database\Factories;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SAVE-####')),
            'type' => CouponType::Percent,
            'value' => 10,
            'min_subtotal_cents' => 0,
            'starts_at' => null,
            'expires_at' => null,
            'max_uses' => null,
            'used_count' => 0,
        ];
    }

    public function fixed(int $cents): static
    {
        return $this->state(fn () => ['type' => CouponType::Fixed, 'value' => $cents]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
