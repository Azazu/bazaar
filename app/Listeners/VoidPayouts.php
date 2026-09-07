<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use App\Models\Order;

/**
 * A cancelled or refunded order must not pay its vendors: the pending payout rows
 * created on OrderPaid are marked cancelled. Money already paid out is a different
 * story (clawback) and out of scope while payouts are calculations, not transfers.
 */
class VoidPayouts
{
    public function handleCancelled(OrderCancelled $event): void
    {
        $this->void($event->order);
    }

    public function handleRefunded(OrderRefunded $event): void
    {
        $this->void($event->order);
    }

    private function void(Order $order): void
    {
        foreach ($order->subOrders as $subOrder) {
            $subOrder->payout?->update(['status' => 'cancelled']);
        }
    }
}
