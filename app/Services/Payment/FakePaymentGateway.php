<?php

namespace App\Services\Payment;

use App\Models\Order;
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
}
