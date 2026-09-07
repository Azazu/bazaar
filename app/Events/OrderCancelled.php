<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCancelled
{
    use Dispatchable, SerializesModels;

    /** @param  bool  $stockWasDeducted  whether the order had been paid (and stock taken) before it was cancelled */
    public function __construct(public Order $order, public bool $stockWasDeducted) {}
}
