<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Sandbox stand-in for a real provider (Stripe). Simulates the intent step
 * without any network call or real money. Swap for a StripeGateway later.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function createIntent(Order $order): string
    {
        return 'fake_'.Str::uuid()->toString();
    }

    public function refund(Payment $payment): void
    {
        // Nothing to call in the sandbox; the real gateway would issue the provider refund here.
    }
}
