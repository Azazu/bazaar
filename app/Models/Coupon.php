<?php

namespace App\Models;

use App\Enums\CouponType;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_subtotal_cents',
        'starts_at',
        'expires_at',
        'max_uses',
        'used_count',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** Is this coupon usable right now for the given subtotal (in cents)? */
    public function isValidFor(int $subtotalCents): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->expires_at === null || $this->expires_at->gte($now))
            && ($this->max_uses === null || $this->used_count < $this->max_uses)
            && $subtotalCents >= $this->min_subtotal_cents;
    }

    /** Discount in cents for the given subtotal, never exceeding it. */
    public function discountFor(int $subtotalCents): int
    {
        $discount = match ($this->type) {
            CouponType::Percent => (int) floor($subtotalCents * $this->value / 100),
            CouponType::Fixed => $this->value,
        };

        return min($discount, $subtotalCents);
    }
}
