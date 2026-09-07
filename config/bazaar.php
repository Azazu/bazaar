<?php

return [
    /*
    | Platform commission taken from each sub-order on payment.
    | "0.10" = 10%. Kept as a string — brick/math needs exact decimals, not floats.
    | Admin-configurable later; a config value for now.
    */
    'commission_rate' => (string) env('BAZAAR_COMMISSION_RATE', '0.10'),

    /*
    | How long an account cart (Redis, keyed by user) lives without activity.
    | Guest carts live in the session and follow its lifetime instead.
    */
    'cart_ttl_days' => (int) env('BAZAAR_CART_TTL_DAYS', 30),

    /*
    | Which PaymentGateway implementation to bind: "fake" (sandbox, no keys, settles instantly)
    | or "stripe" (test mode via Stripe.js + webhook; needs the services.stripe.* keys).
    */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'fake'),
];
