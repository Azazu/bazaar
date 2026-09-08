<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Events\OrderUnfulfillable;

/**
 * A coupon use is reserved when the order is placed (so max_uses can't be oversubscribed by
 * unpaid checkouts) and consumed when the order is fulfilled. An order that is cancelled —
 * before payment, after payment but before fulfilment, because an item sold out, or because
 * it was never paid and expired (orders:expire-pending) — never used it, so the reservation
 * goes back. A refund after fulfilment keeps the use: the
 * discount was applied to goods that shipped.
 */
class ReleaseCouponReservation
{
    public function handleCancelled(OrderCancelled $event): void
    {
        $event->order->coupon?->release();
    }

    public function handleUnfulfillable(OrderUnfulfillable $event): void
    {
        $event->order->coupon?->release();
    }
}
