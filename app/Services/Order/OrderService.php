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
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\ModelStates\State;

/**
 * Order-level reversals — cancel and refund — that move money, stock and the sub-orders
 * together. The state machine decides whether a transition is legal; this decides what it
 * entails and makes sure it happens exactly once.
 *
 * Exactly-once comes from a claim under row locks: the order and all its sub-orders are
 * locked, the precondition is re-checked on that locked snapshot, and only then does the
 * transition happen, in the same transaction as restocking (row-locked itself), voiding the
 * payouts and recording that the money is owed back. A second caller — a double click, a
 * parallel admin, or a vendor advancing a sub-order (SubOrderService takes the same locks in
 * the same order) — waits for the lock and then sees a state that no longer allows the move.
 *
 * The one thing that can't live inside the transaction is the provider refund: it is queued
 * to run after commit (RefundPayment), idempotent and retried, so a crash or a timeout between
 * the two halves leaves a refund_pending payment to finish, never money kept or paid twice.
 */
class OrderService
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Stop an order before fulfilment. A paid one is refunded and its stock goes back. */
    public function cancel(Order $order): Order
    {
        return $this->reverse($order, function (Order $locked) {
            // The parent's state alone isn't enough: once any vendor has shipped, this is a refund, not a cancel.
            if (! $locked->isCancellable()) {
                throw CouldNotPerformTransition::notFound($locked->status->getValue(), Cancelled::getMorphClass(), $locked);
            }

            $wasPaid = $locked->status instanceof Paid;

            $locked->status->transitionTo(Cancelled::class);
            $this->transitionSubOrders($locked, SubOrderCancelled::class);

            if ($wasPaid) {
                $this->payments->refund($locked);
            }

            // A pending order never took stock (that happens on payment), hence the flag.
            OrderCancelled::dispatch($locked, $wasPaid);
        });
    }

    /** Return the money after fulfilment started (processing → delivered); stock goes back. */
    public function refund(Order $order): Order
    {
        return $this->reverse($order, function (Order $locked) {
            if (! $locked->status->canTransitionTo(Refunded::class)) {
                throw CouldNotPerformTransition::notFound($locked->status->getValue(), Refunded::getMorphClass(), $locked);
            }

            $locked->status->transitionTo(Refunded::class);
            $this->transitionSubOrders($locked, SubOrderRefunded::class);
            $this->payments->refund($locked);

            OrderRefunded::dispatch($locked);
        });
    }

    /**
     * Run $reversal on a locked, freshly loaded copy of the order (with its sub-orders locked
     * too), inside one transaction. Lock order: order row first, then its sub-orders by id —
     * SubOrderService::advance() uses the same order, so the two can't deadlock.
     *
     * @param  Closure(Order): void  $reversal
     */
    private function reverse(Order $order, Closure $reversal): Order
    {
        DB::transaction(function () use ($order, $reversal) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $locked->setRelation('subOrders', $locked->subOrders()->orderBy('id')->lockForUpdate()->get());
            $locked->load('items');

            $reversal($locked);
        });

        return $order->refresh();
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
