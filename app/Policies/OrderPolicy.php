<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\States\Order\Pending;

class OrderPolicy
{
    /** A buyer sees only their own orders. */
    public function view(User $user, Order $order): bool
    {
        return $order->buyer_id === $user->id;
    }

    /** Only the buyer may pay, and only while the order is still pending. */
    public function pay(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $order->status instanceof Pending;
    }

    /** The buyer may cancel their own order as long as no vendor has started fulfilment. */
    public function cancel(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $order->isCancellable();
    }

    /** Refunds are an admin operation (Gate::before); buyers ask support. */
    public function refund(User $user, Order $order): bool
    {
        return false;
    }
}
