<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Exceptions\CouponUnavailableException;
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

    /**
     * Take one use of this coupon for an order being placed. Call inside the checkout
     * transaction: the row is locked, re-validated on the locked copy and only then counted,
     * so two checkouts racing for the last use of a coupon can't both get it. used_count is
     * therefore "reserved or consumed": ReleaseCouponReservation gives it back when the order
     * is cancelled instead of fulfilled.
     *
     * Returns the locked copy: the caller must compute the discount from *that* (value/type may
     * have been edited since the buyer applied the code), never from the instance it held.
     *
     * @throws CouponUnavailableException
     */
    public function reserve(int $subtotalCents): static
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isValidFor($subtotalCents)) {
            throw new CouponUnavailableException($locked);
        }

        $locked->increment('used_count');
        $this->setRawAttributes($locked->getAttributes()); // the stale instance catches up too

        return $locked;
    }

    /** Give a reserved use back (never below zero). */
    public function release(): void
    {
        static::query()->whereKey($this->getKey())->where('used_count', '>', 0)->decrement('used_count');
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
