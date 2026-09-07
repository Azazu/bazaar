<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use App\Services\Stock\StockManager;

class RestoreStock
{
    public function __construct(private readonly StockManager $stock) {}

    /**
     * Put stock back when an order is cancelled after payment, or refunded.
     * A pending order never took stock (it is deducted on payment), so cancelling
     * one restores nothing — hence the flag on the event.
     */
    public function handleCancelled(OrderCancelled $event): void
    {
        if ($event->stockWasDeducted) {
            $this->stock->restoreForOrder($event->order);
        }
    }

    public function handleRefunded(OrderRefunded $event): void
    {
        $this->stock->restoreForOrder($event->order);
    }
}
