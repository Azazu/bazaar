<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

interface PaymentGateway
{
    /** Stored on the Payment row so a refund knows which provider to talk to. */
    public function name(): string;

    /**
     * Create a payment intent for the order at the provider. Stripe returns an id plus the
     * client secret the browser needs; the sandbox mints an id and settles immediately.
     */
    public function createIntent(Order $order): PaymentIntentData;

    /** Return the money for a succeeded payment at the provider. */
    public function refund(Payment $payment): void;
}
