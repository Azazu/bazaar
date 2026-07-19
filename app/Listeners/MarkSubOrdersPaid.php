<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\States\SubOrder\Paid;
use App\States\SubOrder\Pending;

class MarkSubOrdersPaid
{
    /**
     * When the parent order is paid, move each of its sub-orders to paid so each
     * vendor can start processing their part independently.
     */
    public function handle(OrderPaid $event): void
    {
        foreach ($event->order->subOrders as $subOrder) {
            if ($subOrder->status instanceof Pending) {
                $subOrder->status->transitionTo(Paid::class);
            }
        }
    }
}
