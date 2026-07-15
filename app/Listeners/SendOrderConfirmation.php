<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Notifications\OrderConfirmed;

class SendOrderConfirmation
{
    /**
     * Sync listener: it only *enqueues* the notification (OrderConfirmed is ShouldQueue),
     * so the paid transaction stays fast. With the queue's after_commit setting the job is
     * released only once the payment transaction actually commits.
     */
    public function handle(OrderPaid $event): void
    {
        $event->order->buyer->notify(new OrderConfirmed($event->order));
    }
}
