<?php

namespace App\Http\Controllers;

use App\Services\Payment\PaymentService;
use App\Services\Payment\StripeTestMode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe's callback. The signature check is what makes this trustworthy: anyone can POST
 * here, only Stripe can sign with our endpoint secret. Everything after that is delegated
 * to PaymentService, which is idempotent — Stripe retries until it gets a 2xx.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $payments): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature.', 400);
        }

        // A live-mode event can only mean the endpoint is wired to a live account. Never act on
        // it; answer 4xx so the failure is visible in the Stripe dashboard instead of swallowed.
        if (StripeTestMode::isLiveEvent($event)) {
            Log::critical('Live-mode Stripe event received by a test-mode application; ignored.', ['event' => $event->id, 'type' => $event->type]);

            return response('This endpoint only accepts test-mode events.', 400);
        }

        /** @var string $intentId */
        $intentId = $event->data->object->id ?? '';

        try {
            match ($event->type) {
                'payment_intent.succeeded' => $payments->confirm($event->id, $intentId),
                'payment_intent.payment_failed' => $payments->fail($intentId),
                default => null, // not subscribed to anything else; acknowledge so Stripe stops retrying
            };
        } catch (ModelNotFoundException) {
            // An intent we never created (another app on the same Stripe account, dashboard tests):
            // nothing to do, and a 4xx/5xx would only make Stripe retry for days.
            Log::warning('Stripe webhook for unknown payment intent.', ['event' => $event->id, 'intent' => $intentId]);
        }

        return response()->noContent();
    }
}
