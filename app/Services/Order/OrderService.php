<?php

namespace App\Services\Order;

use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use App\Models\Order;
use App\Models\SubOrder;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Paid;
use App\States\Order\Refunded;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use App\States\SubOrder\Refunded as SubOrderRefunded;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\ModelStates\State;

/**
 * Order-level transitions that move money, stock and the sub-orders together.
 * The state machine decides whether a transition is legal; this decides what it entails.
 *
 * Both operations: check the transition first, return the money at the provider
 * (a network call — kept outside the DB transaction), then in one transaction change
 * the order and its sub-orders and fire the event whose sync listeners restore stock
 * (row-locked) and void payouts. Either all of the local changes land, or none.
 */
class OrderService
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Stop an order before fulfilment. A paid one is refunded and its stock goes back. */
    public function cancel(Order $order): Order
    {
        // The parent's state alone isn't enough: once any vendor has shipped, this is a refund, not a cancel.
        if (! $order->isCancellable()) {
            throw CouldNotPerformTransition::notFound($order->status->getValue(), Cancelled::getMorphClass(), $order);
        }

        $wasPaid = $order->status instanceof Paid;

        if ($wasPaid) {
            $this->payments->refund($order);
        }

        DB::transaction(function () use ($order, $wasPaid) {
            $order->status->transitionTo(Cancelled::class);
            $this->transitionSubOrders($order, SubOrderCancelled::class);

            // A pending order never took stock (that happens on payment), hence the flag.
            OrderCancelled::dispatch($order, $wasPaid);
        });

        return $order->refresh();
    }

    /** Return the money after fulfilment started (processing → delivered); stock goes back. */
    public function refund(Order $order): Order
    {
        $this->assertCanTransition($order, Refunded::class);

        $this->payments->refund($order);

        DB::transaction(function () use ($order) {
            $order->status->transitionTo(Refunded::class);
            $this->transitionSubOrders($order, SubOrderRefunded::class);

            OrderRefunded::dispatch($order);
        });

        return $order->refresh();
    }

    /**
     * Fail before any side effect if the state machine would reject the move — the
     * refund call must not happen for an order that then can't be transitioned.
     *
     * @param  class-string<State<Order>>  $target
     */
    private function assertCanTransition(Order $order, string $target): void
    {
        if (! $order->status->canTransitionTo($target)) {
            throw CouldNotPerformTransition::notFound($order->status->getValue(), $target::getMorphClass(), $order);
        }
    }

    /**
     * Drag the sub-orders along with the parent, skipping any already in a final state.
     *
     * @param  class-string<State<SubOrder>>  $target
     */
    private function transitionSubOrders(Order $order, string $target): void
    {
        foreach ($order->subOrders as $subOrder) {
            if ($subOrder->status->canTransitionTo($target)) {
                $subOrder->status->transitionTo($target);
            }
        }
    }
}
