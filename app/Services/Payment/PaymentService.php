<?php

namespace App\Services\Payment;

use App\Events\OrderPaid;
use App\Events\OrderUnfulfillable;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderNotPayableException;
use App\Exceptions\SurplusPaymentException;
use App\Jobs\RefundPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Stock\StockManager;
use App\States\Order\Cancelled;
use App\States\Order\Paid;
use App\States\Order\Pending;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly StockManager $stock,
    ) {}

    /**
     * Start (or resume) the payment of a pending order.
     *
     * An order has at most one open attempt. A second click, a page reload or a retry after a
     * lost response gets the *same* intent back — never a second one that could also be
     * charged. That needs two things: the order row is locked for the duration, so parallel
     * calls line up instead of each minting an intent; and an existing pending attempt is
     * resumed through the gateway (Stripe re-reads the intent and its client secret; we never
     * store the secret). Only when the provider says the open intent is dead do we start a new
     * one, and the "attempt-N" idempotency key is then computed under the same lock.
     *
     * The provider call happens inside the lock on purpose: releasing it first would reopen
     * the window this method closes. It costs one short row lock per payment start.
     *
     * The state check lives here, not only in OrderPolicy: policies can be bypassed
     * (admins pass Gate::before), the domain invariant must not be.
     */
    public function start(Order $order): StartedPayment
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->status instanceof Pending) {
                throw new OrderNotPayableException($order);
            }

            // Don't take money for something already sold out; the locked decrement on payment
            // still guards the race window, and confirm() refunds if it loses.
            $this->stock->assertAvailable($order);

            $open = $order->payments()
                ->where('status', 'pending')
                ->where('gateway', $this->gateway->name())
                ->latest('id')
                ->first();

            if ($open !== null) {
                $intent = $this->gateway->resumeIntent($open);

                if ($intent !== null) {
                    return new StartedPayment($open, $intent->clientSecret, $intent->requiresClientAction);
                }

                $open->update(['status' => 'failed']); // the provider can no longer complete it
            }

            $intent = $this->gateway->createIntent($order);

            $payment = $order->payments()->create([
                'gateway' => $this->gateway->name(),
                'transaction_id' => $intent->id,
                'status' => 'pending',
                'amount_cents' => $order->total_cents,
                'currency' => $order->currency,
            ]);

            return new StartedPayment($payment, $intent->clientSecret, $intent->requiresClientAction);
        });
    }

    /** The provider reported a failed attempt: record it; the order stays pending and can be retried. */
    public function fail(string $transactionId): void
    {
        Payment::where('transaction_id', $transactionId)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }

    /**
     * Return the money for an order. Call it inside the transaction that reverses the order:
     * it only *records* that the money is owed back (payment → refund_pending), so that
     * decision commits atomically with the order's new state. The provider call itself runs
     * in RefundPayment after commit, retried until it succeeds — a network call can't be part
     * of a database transaction, so it is made idempotent and repeatable instead.
     */
    public function refund(Order $order): void
    {
        $payment = $order->payment ?? $order->payments()->where('status', 'succeeded')->latest()->first();

        if ($payment === null || $payment->status !== 'succeeded') {
            return; // nothing was ever charged (or it has already been returned)
        }

        $this->requestRefund($payment);
    }

    /**
     * Second half of a refund, run by RefundPayment: return the money at the provider and
     * record it. Idempotent — a payment that is not (or no longer) refund_pending is skipped,
     * and the gateway keys the refund by transaction so even a repeat call can't pay twice.
     */
    public function executeRefund(Payment $payment): void
    {
        $payment->refresh();

        if ($payment->status !== 'refund_pending') {
            return;
        }

        $this->gateway->refund($payment);

        $payment->update(['status' => 'refunded']);
    }

    /** Mark the money as owed back and queue the provider call for after the surrounding commit. */
    private function requestRefund(Payment $payment): void
    {
        $payment->update(['status' => 'refund_pending']);

        RefundPayment::dispatch($payment);
    }

    /**
     * Handle a "payment succeeded" event (in production: a Stripe webhook).
     *
     * Idempotent by design — a provider may deliver the same event more than once:
     *   1. the event id is recorded in a unique ledger; a duplicate is ignored;
     *   2. the payment is only marked succeeded once;
     *   3. the order only transitions pending -> paid once, under a row lock, and remembers
     *      which payment did it (Order::payment). A different payment that succeeds for the
     *      same order afterwards is surplus: its money goes straight back.
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
        } catch (SurplusPaymentException $surplus) {
            $this->refundSurplus($eventId, $surplus->payment);
        }
    }

    /**
     * The customer paid for an order we can't ship: record the reversal (payment refund_pending,
     * order and its sub-orders cancelled, event id in the ledger so redeliveries are no-ops)
     * and let RefundPayment return the money after commit.
     */
    private function refundUnfulfillable(string $eventId, string $transactionId, InsufficientStockException $soldOut): void
    {
        $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
        $order = $payment->order ?? throw new LogicException("Payment #{$payment->id} has no order.");

        $this->recordRefund($eventId, $payment, function () use ($order, $soldOut) {
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

    /**
     * A second charge landed for an order another attempt already settled (or one that was
     * cancelled in between). The order is left exactly as it is; only this payment is reversed.
     */
    private function refundSurplus(string $eventId, Payment $payment): void
    {
        Log::warning('Surplus payment refunded: the order was already settled by another attempt.', [
            'order' => $payment->order_id,
            'payment' => $payment->id,
            'transaction' => $payment->transaction_id,
        ]);

        $this->recordRefund($eventId, $payment);
    }

    /**
     * In one transaction: ledger row, payment marked refund_pending, plus whatever else the
     * caller needs to record; the provider call follows after commit (RefundPayment). The
     * ledger row makes a redelivery of the same event a no-op even while the job is pending.
     */
    private function recordRefund(string $eventId, Payment $payment, ?Closure $andRecord = null): void
    {
        DB::transaction(function () use ($eventId, $payment, $andRecord) {
            try {
                PaymentEvent::firstOrCreate(['event_id' => $eventId]);
            } catch (UniqueConstraintViolationException) {
                // a concurrent delivery is recording the same outcome
            }

            $this->requestRefund($payment);

            if ($andRecord !== null) {
                $andRecord();
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

            if (in_array($payment->status, ['succeeded', 'refund_pending', 'refunded'], true)) {
                return; // this charge has already been accounted for, under another event id
            }

            // Lock the order so two attempts succeeding at once can't both see "pending".
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first()
                ?? throw new LogicException("Payment #{$payment->id} has no order.");

            if (! $order->status instanceof Pending) {
                throw new SurplusPaymentException($payment); // rolls back, incl. the ledger row
            }

            $payment->update(['status' => 'succeeded']);

            $order->payment()->associate($payment)->save();
            $order->status->transitionTo(Paid::class);
            OrderPaid::dispatch($order->refresh());
        });
    }
}
