<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Cart\CartService;
use App\Services\Cart\CartStorageFactory;

/**
 * Once paid, the ordered lines leave the buyer's account cart — and only those lines,
 * so anything added after checkout survives. Checkout itself doesn't touch the cart.
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

        $cart = new CartService($this->storages->forUser($buyer));

        foreach ($event->order->items as $item) {
            if ($item->product_variant_id !== null) {
                $cart->remove($item->product_variant_id);
            }
        }
    }
}
