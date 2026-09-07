<?php

namespace App\Services\Payment;

use App\Events\OrderPaid;
use App\Events\OrderUnfulfillable;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderNotPayableException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Stock\StockManager;
use App\States\Order\Cancelled;
use App\States\Order\Paid;
use App\States\Order\Pending;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly StockManager $stock,
    ) {}

    /**
     * Start a payment for a pending order: create the provider intent and a local Payment row.
     * The client secret travels back to the caller only — it is never stored.
     *
     * The state check lives here, not only in OrderPolicy: policies can be bypassed
     * (admins pass Gate::before), the domain invariant must not be.
     */
    public function start(Order $order): StartedPayment
    {
        if (! $order->status instanceof Pending) {
            throw new OrderNotPayableException($order);
        }

        // Don't take money for something already sold out; the locked decrement on payment
        // still guards the race window, and confirm() refunds if it loses.
        $this->stock->assertAvailable($order);

        $intent = $this->gateway->createIntent($order);

        $payment = $order->payments()->create([
            'gateway' => $this->gateway->name(),
            'transaction_id' => $intent->id,
            'status' => 'pending',
            'amount_cents' => $order->total_cents,
            'currency' => $order->currency,
        ]);

        return new StartedPayment($payment, $intent->clientSecret, $intent->requiresClientAction);
    }

    /** The provider reported a failed attempt: record it; the order stays pending and can be retried. */
    public function fail(string $transactionId): void
    {
        Payment::where('transaction_id', $transactionId)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }

    /**
     * Return the money for an order: refund the succeeded payment at the provider and
     * record it. Called by OrderService before the order itself changes state.
     */
    public function refund(Order $order): void
    {
        $payment = $order->payments()->where('status', 'succeeded')->latest()->first();

        if ($payment === null) {
            return; // nothing was ever charged
        }

        $this->gateway->refund($payment);

        $payment->update(['status' => 'refunded']);
    }

    /**
     * Handle a "payment succeeded" event (in production: a Stripe webhook).
     *
     * Idempotent by design — a provider may deliver the same event more than once:
     *   1. the event id is recorded in a unique ledger; a duplicate is ignored;
     *   2. the payment is only marked succeeded once;
     *   3. the order only transitions pending -> paid once (state machine guards the rest).
     *
     * Guard 1 also has to survive two deliveries arriving at the same instant. The loser's
     * insert hits the unique index, and firstOrCreate's own recovery (re-read the row) comes
     * up empty under MySQL's REPEATABLE READ, because the winner's row isn't committed inside
     * the loser's snapshot — so the violation surfaces here and *is* the duplicate signal.
     * The ledger row lives inside this transaction, so a rollback releases the event id and a
     * later retry from the provider is applied normally.
     */
    public function confirm(string $eventId, string $transactionId): void
    {
        try {
            $this->applySucceeded($eventId, $transactionId);
        } catch (InsufficientStockException $soldOut) {
            // The transaction above rolled back: the order is still pending, no stock moved, no
            // payouts exist — but the customer *was* charged. Reverse it instead of failing the
            // webhook, which would only make the provider retry into the same shortage for days.
            $this->refundUnfulfillable($eventId, $transactionId, $soldOut);
        }
    }

    /**
     * Money came back for an order we can't ship. Refund first (idempotent at the provider,
     * so a retry after a crash here is safe), then record: payment refunded, order and its
     * sub-orders cancelled, event id in the ledger so redeliveries are no-ops.
     */
    private function refundUnfulfillable(string $eventId, string $transactionId, InsufficientStockException $soldOut): void
    {
        $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
        $order = $payment->order ?? throw new LogicException("Payment #{$payment->id} has no order.");

        $this->gateway->refund($payment);

        DB::transaction(function () use ($eventId, $payment, $order, $soldOut) {
            try {
                PaymentEvent::firstOrCreate(['event_id' => $eventId]);
            } catch (UniqueConstraintViolationException) {
                // a concurrent delivery is recording the same outcome
            }

            $payment->update(['status' => 'refunded']);

            if ($order->status instanceof Pending) {
                $order->status->transitionTo(Cancelled::class);

                foreach ($order->subOrders as $subOrder) {
                    if ($subOrder->status->canTransitionTo(SubOrderCancelled::class)) {
                        $subOrder->status->transitionTo(SubOrderCancelled::class);
                    }
                }

                OrderUnfulfillable::dispatch($order->refresh(), $soldOut->variant);
            }
        });
    }

    /** The happy path of confirm(): everything inside one transaction, see the notes on confirm(). */
    private function applySucceeded(string $eventId, string $transactionId): void
    {
        DB::transaction(function () use ($eventId, $transactionId) {
            try {
                $event = PaymentEvent::firstOrCreate(['event_id' => $eventId]);
            } catch (UniqueConstraintViolationException) {
                return; // a concurrent delivery of this same event is applying it
            }

            if (! $event->wasRecentlyCreated) {
                return; // already processed this exact event
            }

            $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();

            if ($payment->status === 'succeeded') {
                return;
            }

            $payment->update(['status' => 'succeeded']);

            $order = $payment->order ?? throw new LogicException("Payment #{$payment->id} has no order.");

            if ($order->status instanceof Pending) {
                $order->status->transitionTo(Paid::class);
                OrderPaid::dispatch($order->refresh());
            }
        });
    }
}
