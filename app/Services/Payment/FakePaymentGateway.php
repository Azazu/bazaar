<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Sandbox stand-in: no network, no real money. Intents need no client action, so the
 * caller simulates the provider's "succeeded" callback right away. Selected with
 * PAYMENT_GATEWAY=fake — the default, so the app runs without any Stripe keys.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createIntent(Order $order): PaymentIntentData
    {
        return new PaymentIntentData('fake_'.Str::uuid()->toString());
    }

    public function resumeIntent(Payment $payment): ?PaymentIntentData
    {
        return new PaymentIntentData($payment->transaction_id);
    }

    public function refund(Payment $payment): void
    {
        // Nothing to call in the sandbox.
    }
}
