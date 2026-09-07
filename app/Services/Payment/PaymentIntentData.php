<?php

namespace App\Services\Payment;

/** What a gateway hands back after creating an intent. */
final readonly class PaymentIntentData
{
    public function __construct(
        /** Provider-side id; stored as Payment::transaction_id and matched by the webhook. */
        public string $id,
        /** Secret the client needs to confirm the intent (Stripe.js / mobile SDK). Never persisted. */
        public ?string $clientSecret = null,
        /** True when the client must finish the payment itself; false when the sandbox settles at once. */
        public bool $requiresClientAction = false,
    ) {}
}
