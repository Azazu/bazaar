<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

interface PaymentGateway
{
    /**
     * Create a payment intent for the order at the provider and return its transaction id.
     * The real StripeGateway (backlog) will call the Stripe API here; FakePaymentGateway
     * just mints a fake id — no network, no real money.
     */
    public function createIntent(Order $order): string;

    /** Return the money for a succeeded payment at the provider. */
    public function refund(Payment $payment): void;
}
