<?php

namespace App\Services\Payment;

use App\Events\OrderPaid;
use App\Exceptions\OrderNotPayableException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\States\Order\Paid;
use App\States\Order\Pending;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Start a payment for a pending order: create the provider intent and a local Payment row.
     *
     * The state check lives here, not only in OrderPolicy: policies can be bypassed
     * (admins pass Gate::before), the domain invariant must not be.
     */
    public function start(Order $order): Payment
    {
        if (! $order->status instanceof Pending) {
            throw new OrderNotPayableException($order);
        }

        return $order->payments()->create([
            'gateway' => 'fake',
            'transaction_id' => $this->gateway->createIntent($order),
            'status' => 'pending',
            'amount_cents' => $order->total_cents,
            'currency' => $order->currency,
        ]);
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

            $order = $payment->order;

            if ($order->status instanceof Pending) {
                $order->status->transitionTo(Paid::class);
                OrderPaid::dispatch($order->refresh());
            }
        });
    }
}
