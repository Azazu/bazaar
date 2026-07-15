<?php

namespace App\Services\Payment;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\States\Order\Paid;
use App\States\Order\Pending;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Start a payment for a pending order: create the provider intent and a local Payment row.
     */
    public function start(Order $order): Payment
    {
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
     */
    public function confirm(string $eventId, string $transactionId): void
    {
        DB::transaction(function () use ($eventId, $transactionId) {
            $event = PaymentEvent::firstOrCreate(['event_id' => $eventId]);

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
