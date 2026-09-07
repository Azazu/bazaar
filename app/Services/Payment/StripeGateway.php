<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use Stripe\StripeClient;

/**
 * Stripe in test mode, via PaymentIntents. The browser confirms the intent with Stripe.js
 * (Payment Element); Stripe then calls our webhook, which is what actually marks the order
 * paid — never the redirect back to the site.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createIntent(Order $order): PaymentIntentData
    {
        $intent = $this->stripe->paymentIntents->create(
            [
                'amount' => $order->total_cents, // Stripe wants minor units — exactly what we store
                'currency' => strtolower($order->currency),
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => ['order_id' => (string) $order->id],
            ],
            // A retried request for the same attempt returns the same intent instead of a duplicate.
            ['idempotency_key' => sprintf('order-%d-attempt-%d', $order->id, $order->payments()->count() + 1)],
        );

        return new PaymentIntentData($intent->id, $intent->client_secret, requiresClientAction: true);
    }

    public function resumeIntent(Payment $payment): ?PaymentIntentData
    {
        $intent = $this->stripe->paymentIntents->retrieve($payment->transaction_id);

        if ($intent->status === 'canceled') {
            return null; // Stripe won't confirm it any more; a new intent is needed
        }

        // Any other status is still confirmable (or already succeeded, in which case the webhook
        // is on its way and the Payment Element will simply report that).
        return new PaymentIntentData($intent->id, $intent->client_secret, requiresClientAction: true);
    }

    public function refund(Payment $payment): void
    {
        // One refund per payment, however many times we get here (webhook retries, crashes after the call).
        $this->stripe->refunds->create(
            ['payment_intent' => $payment->transaction_id],
            ['idempotency_key' => 'refund-'.$payment->transaction_id],
        );
    }
}
