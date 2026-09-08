<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Payment settled but a line could not be delivered (sold out, or its variant is gone):
 * the order was cancelled and refunded. $item names the line for the buyer.
 */
class OrderUnfulfillable
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order, public string $item) {}
}
