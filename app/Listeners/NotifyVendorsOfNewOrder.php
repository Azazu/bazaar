<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Notifications\NewOrderReceived;

/**
 * Each store in a paid order gets its own "new order" mail with only its items —
 * a vendor never sees what the buyer ordered from other stores.
 */
class NotifyVendorsOfNewOrder
{
    public function handle(OrderPaid $event): void
    {
        foreach ($event->order->subOrders as $subOrder) {
            $subOrder->store?->owner?->notify(new NewOrderReceived($subOrder));
        }
    }
}
