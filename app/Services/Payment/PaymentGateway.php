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

    /**
     * Pick up an attempt that was started but never completed (lost response, page reload),
     * so the buyer finishes the same intent instead of getting a second chargeable one.
     * Returns null when the provider can no longer complete it — the caller starts afresh.
     */
    public function resumeIntent(Payment $payment): ?PaymentIntentData;

    /**
     * Return the money for a succeeded payment at the provider. Returns the provider's
     * reference for the refund (Stripe: the refund id) when it has one, for the audit trail.
     */
    public function refund(Payment $payment): ?string;
}
