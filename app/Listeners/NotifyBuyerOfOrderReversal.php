<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use App\Notifications\OrderCancelledNotice;
use App\Notifications\OrderRefundedNotice;

class NotifyBuyerOfOrderReversal
{
    public function handleCancelled(OrderCancelled $event): void
    {
        // stockWasDeducted doubles as "money was taken": both happen on payment.
        $event->order->buyer?->notify(new OrderCancelledNotice($event->order, refunded: $event->stockWasDeducted));
    }

    public function handleRefunded(OrderRefunded $event): void
    {
        $event->order->buyer?->notify(new OrderRefundedNotice($event->order));
    }
}
