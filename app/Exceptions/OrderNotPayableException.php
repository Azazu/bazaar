<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/** A payment was requested for an order that is not pending (already paid, cancelled, …). */
class OrderNotPayableException extends RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct("Order #{$order->id} is {$order->status->getValue()} and cannot be paid.");
    }
}
