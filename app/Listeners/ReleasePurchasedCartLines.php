<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Cart\CartService;
use App\Services\Cart\CartStorageFactory;

/**
 * Once paid, the ordered quantities leave the buyer's account cart — exactly the snapshot
 * quantities, so anything added after checkout survives, including more units of the same
 * variant. Done once per order (idempotency token) and under the cart's lock, so a replayed
 * event can't subtract twice and a concurrent "add" isn't overwritten. Checkout itself
 * doesn't touch the cart.
 */
class ReleasePurchasedCartLines
{
    public function __construct(private readonly CartStorageFactory $storages) {}

    public function handle(OrderPaid $event): void
    {
        $buyer = $event->order->buyer;

        if ($buyer === null) {
            return;
        }

        $purchased = [];

        foreach ($event->order->items as $item) {
            if ($item->product_variant_id !== null) {
                $purchased[$item->product_variant_id] = ($purchased[$item->product_variant_id] ?? 0) + $item->qty;
            }
        }

        (new CartService($this->storages->forUser($buyer)))->releaseOnce("order-{$event->order->id}", $purchased);
    }
}
