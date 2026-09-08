<?php

namespace App\Services\Payment;

use App\Exceptions\UnknownPaymentGatewayException;
use App\Models\Payment;
use Illuminate\Contracts\Container\Container;

/**
 * Which gateway talks to which provider. New payments go to the configured default; anything
 * that touches an *existing* payment (a refund, resuming an intent) must use the gateway that
 * payment was made with — `payments.gateway` — even if PAYMENT_GATEWAY has been switched since.
 * Otherwise a Stripe charge could be "refunded" locally through the sandbox without any money
 * moving, or a sandbox transaction id sent to Stripe.
 */
class PaymentGatewayRegistry
{
    public function __construct(private readonly Container $app) {}

    /** The gateway new payments are started with (PAYMENT_GATEWAY). */
    public function default(): PaymentGateway
    {
        return $this->app->make(PaymentGateway::class);
    }

    /**
     * The gateway a payment belongs to. An unknown or unconfigured provider throws, and the
     * caller must leave the payment as it is — there is no local substitute for a provider call.
     */
    public function for(Payment $payment): PaymentGateway
    {
        // Decide by name first, without building anything: the current default may not even be
        // constructible (Stripe without a key), and that must not stop a sandbox refund.
        if ($payment->gateway === $this->defaultName()) {
            return $this->default(); // the container binding may be a test double; honour it
        }

        return $this->named($payment->gateway);
    }

    private function defaultName(): string
    {
        return (string) config('bazaar.payment_gateway');
    }

    public function named(string $name): PaymentGateway
    {
        return match ($name) {
            'stripe' => $this->app->make(StripeGateway::class),
            'fake' => new FakePaymentGateway,
            default => throw new UnknownPaymentGatewayException($name),
        };
    }
}
