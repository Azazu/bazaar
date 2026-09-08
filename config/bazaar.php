<?php

return [
    /*
    | Initial platform commission per sub-order — only the seed for the `marketplace`
    | settings group (database/settings); the live value is edited in the admin panel.
    | "0.10" = 10%. Kept as a string — brick/math needs exact decimals, not floats.
    */
    'commission_rate' => (string) env('BAZAAR_COMMISSION_RATE', '0.10'),

    /*
    | How long an account cart (Redis, keyed by user) lives without activity.
    | Guest carts live in the session and follow its lifetime instead.
    */
    'cart_ttl_days' => (int) env('BAZAAR_CART_TTL_DAYS', 30),

    /*
    | How long an unpaid (pending) order may sit before `orders:expire-pending` cancels it,
    | releasing the coupon use it reserved. An order whose payment attempt was touched within
    | this window is left alone, so a buyer mid-payment is never cut off.
    */
    'pending_order_ttl_hours' => (int) env('BAZAAR_PENDING_ORDER_TTL_HOURS', 24),

    /*
    | How long rows of the payment-event ledger (idempotency + audit trail of provider events)
    | are kept before `model:prune` removes them. The payment itself — status, refund reference,
    | timestamps — is the durable financial record and is never pruned.
    */
    'payment_event_retention_days' => (int) env('BAZAAR_PAYMENT_EVENT_RETENTION_DAYS', 400),

    /*
    | Which PaymentGateway implementation to bind: "fake" (sandbox, no keys, settles instantly)
    | or "stripe" (test mode via Stripe.js + webhook; needs the services.stripe.* keys).
    */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),
];
