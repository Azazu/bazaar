<?php

namespace App\Services\Payment;

use App\Models\Payment;

/** A freshly started payment plus the transient data the client needs to complete it. */
final readonly class StartedPayment
{
    public function __construct(
        public Payment $payment,
        public ?string $clientSecret,
        public bool $requiresClientAction,
    ) {}
}
