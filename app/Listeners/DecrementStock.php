<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Stock\StockManager;

class DecrementStock
{
    public function __construct(private readonly StockManager $stock) {}

    /**
     * Runs synchronously (not queued) so stock is decremented inside the same
     * transaction that marks the order paid — if stock is short, the whole
     * payment rolls back and the order stays pending rather than overselling.
     */
    public function handle(OrderPaid $event): void
    {
        $this->stock->decrementForOrder($event->order);
    }
}
