<?php

namespace App\Exceptions;

use App\Models\Coupon;

/**
 * The coupon can't be applied to this order after all: it expired, the subtotal fell under
 * its minimum, or its last use was taken by another checkout a moment ago. Decided on the
 * locked coupon row inside the checkout transaction, so the order is not created.
 */
class CouponUnavailableException extends CheckoutBlockedException
{
    public function __construct(public readonly Coupon $coupon)
    {
        parent::__construct("Coupon {$coupon->code} is no longer available for this order.");
    }
}
