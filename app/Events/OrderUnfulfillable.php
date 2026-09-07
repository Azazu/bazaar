<?php

namespace App\Events;

use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Payment settled but an item had sold out meanwhile: the order was cancelled and refunded. */
class OrderUnfulfillable
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order, public ProductVariant $soldOut) {}
}
