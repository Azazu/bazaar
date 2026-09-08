<?php

use App\Exceptions\UnknownPaymentGatewayException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderService;
use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayRegistry;
use App\Services\Payment\PaymentService;
use App\Services\Payment\StripeGateway;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Tests\Support\RecordingStripeHttpClient;

/*
 * HI-005: a refund is sent to the gateway that took the payment (payments.gateway), never to
 * whatever PAYMENT_GATEWAY happens to be at the time. Uses the same recording Stripe HTTP stub
 * as StripeGatewayTest, so "went to Stripe" is observable without the network.
 */

afterEach(function () {
    ApiRequestor::setHttpClient(null);
    app()->forgetInstance(StripeClient::class);
});

function refundPending(string $gateway, string $transactionId): Payment
{
    return Order::factory()->paid()->create()->payments()->create([
        'gateway' => $gateway, 'transaction_id' => $transactionId, 'status' => 'refund_pending',
        'amount_cents' => 1000, 'currency' => 'USD',
    ]);
}

it('refunds a Stripe payment through Stripe even when the default gateway is now the sandbox', function () {
    config(['bazaar.payment_gateway' => 'fake', 'services.stripe.secret' => 'sk_test_dummy']);
    $http = new RecordingStripeHttpClient(['id' => 're_777', 'object' => 'refund']);
    ApiRequestor::setHttpClient($http);
    $payment = refundPending('stripe', 'pi_stripe_1');

    expect(app(PaymentGatewayRegistry::class)->default())->toBeInstanceOf(FakePaymentGateway::class)
        ->and(app(PaymentGatewayRegistry::class)->for($payment))->toBeInstanceOf(StripeGateway::class);

    app(PaymentService::class)->executeRefund($payment);

    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]['url'])->toEndWith('/v1/refunds')
        ->and($http->requests[0]['params'])->toBe(['payment_intent' => 'pi_stripe_1'])
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($payment->fresh()->refund_reference)->toBe('re_777')
        ->and($payment->fresh()->refunded_at)->not->toBeNull();
});

it('never sends a sandbox payment to Stripe even when Stripe is now the default gateway', function () {
    config(['bazaar.payment_gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy']);
    $http = new RecordingStripeHttpClient(['id' => 're_never', 'object' => 'refund']);
    ApiRequestor::setHttpClient($http);
    $payment = refundPending('fake', 'fake_abc');

    expect(app(PaymentGatewayRegistry::class)->default())->toBeInstanceOf(StripeGateway::class);

    app(PaymentService::class)->executeRefund($payment);

    expect($http->requests)->toBe([])                                  // Stripe was never called
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($payment->fresh()->refund_reference)->toBe('fake_refund_fake_abc');
});

it('refunds a sandbox payment through the sandbox even when the current default is Stripe without a key', function () {
    // Switched to Stripe but the key isn't there (yet): the current default can't even be built.
    // A historical sandbox payment must still be refundable — its own gateway needs nothing.
    config(['bazaar.payment_gateway' => 'stripe', 'services.stripe.secret' => null]);
    $http = new RecordingStripeHttpClient(['id' => 're_never', 'object' => 'refund']);
    ApiRequestor::setHttpClient($http);
    $payment = refundPending('fake', 'fake_hist');

    expect(fn () => app(PaymentGatewayRegistry::class)->default())->toThrow(RuntimeException::class, 'STRIPE_SECRET');

    app(PaymentService::class)->executeRefund($payment);

    expect($http->requests)->toBe([])
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($payment->fresh()->refund_reference)->toBe('fake_refund_fake_hist');
});

it('does not mark a payment refunded when its gateway is unknown or unconfigured', function () {
    $unknown = refundPending('paypal', 'PAY-1');
    expect(fn () => app(PaymentService::class)->executeRefund($unknown))->toThrow(UnknownPaymentGatewayException::class);
    expect($unknown->fresh()->status)->toBe('refund_pending')->and($unknown->fresh()->refund_reference)->toBeNull();

    // Stripe payment, but this deployment no longer has a Stripe secret: the job must fail, not pretend.
    config(['bazaar.payment_gateway' => 'fake', 'services.stripe.secret' => null]);
    $stripe = refundPending('stripe', 'pi_orphan');
    expect(fn () => app(PaymentService::class)->executeRefund($stripe))->toThrow(RuntimeException::class, 'STRIPE_SECRET');
    expect($stripe->fresh()->status)->toBe('refund_pending');
});

it('keeps using the original gateway for a cancellation after the configuration was switched', function () {
    // Paid through the sandbox…
    [$order] = paidOrderWithStock();
    expect(Payment::where('order_id', $order->id)->value('gateway'))->toBe('fake');

    // …then the operator switches the marketplace to Stripe.
    config(['bazaar.payment_gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy']);
    $http = new RecordingStripeHttpClient(['id' => 're_x', 'object' => 'refund']);
    ApiRequestor::setHttpClient($http);
    expect(app(PaymentGateway::class))->toBeInstanceOf(StripeGateway::class);

    app(OrderService::class)->cancel($order);

    expect($http->requests)->toBe([])                                  // the sandbox charge stays in the sandbox
        ->and(Payment::where('order_id', $order->id)->value('status'))->toBe('refunded')
        ->and(Payment::where('order_id', $order->id)->value('refund_reference'))->toStartWith('fake_refund_');
});
