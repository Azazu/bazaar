<?php

use App\Models\Order;
use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\StripeGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

/**
 * Stand-in for Stripe's HTTP layer: records every request and answers with canned JSON,
 * so the gateway is exercised end to end without touching the network.
 */
final class RecordingStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, url: string, params: array<string, mixed>, headers: array<int, string>}> */
    public array $requests = [];

    /** @param  array<string, mixed>  $response */
    public function __construct(private array $response) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params, 'headers' => $headers];

        return [json_encode($this->response, JSON_THROW_ON_ERROR), 200, []];
    }
}

afterEach(fn () => ApiRequestor::setHttpClient(null));

it('creates a Stripe PaymentIntent in minor units with an idempotency key', function () {
    $http = new RecordingStripeHttpClient(['id' => 'pi_abc', 'object' => 'payment_intent', 'client_secret' => 'pi_abc_secret_xyz']);
    ApiRequestor::setHttpClient($http);
    $order = Order::factory()->create(['total_cents' => 12345, 'currency' => 'USD']);

    $intent = (new StripeGateway(new StripeClient('sk_test_dummy')))->createIntent($order);

    expect($intent->id)->toBe('pi_abc')
        ->and($intent->clientSecret)->toBe('pi_abc_secret_xyz')
        ->and($intent->requiresClientAction)->toBeTrue();

    $request = $http->requests[0];
    expect($request['method'])->toBe('post')
        ->and($request['url'])->toEndWith('/v1/payment_intents')
        ->and($request['params']['amount'])->toBe(12345)
        ->and($request['params']['currency'])->toBe('usd')
        ->and($request['params']['metadata']['order_id'])->toBe((string) $order->id)
        ->and(implode("\n", $request['headers']))->toContain("Idempotency-Key: order-{$order->id}-attempt-1");
});

it('refunds through the payment intent', function () {
    $http = new RecordingStripeHttpClient(['id' => 're_1', 'object' => 'refund']);
    ApiRequestor::setHttpClient($http);
    $order = Order::factory()->paid()->create();
    $payment = $order->payments()->create([
        'gateway' => 'stripe', 'transaction_id' => 'pi_abc', 'status' => 'succeeded',
        'amount_cents' => $order->total_cents, 'currency' => 'USD',
    ]);

    (new StripeGateway(new StripeClient('sk_test_dummy')))->refund($payment);

    expect($http->requests[0]['url'])->toEndWith('/v1/refunds')
        ->and($http->requests[0]['params'])->toBe(['payment_intent' => 'pi_abc'])
        ->and(implode("\n", $http->requests[0]['headers']))->toContain('Idempotency-Key: refund-pi_abc');
});

it('binds the gateway the config asks for', function () {
    config(['bazaar.payment_gateway' => 'fake']);
    expect(app(PaymentGateway::class))->toBeInstanceOf(FakePaymentGateway::class);

    config(['bazaar.payment_gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy']);
    expect(app(PaymentGateway::class))->toBeInstanceOf(StripeGateway::class);
});

it('refuses to run Stripe without a secret key', function () {
    config(['bazaar.payment_gateway' => 'stripe', 'services.stripe.secret' => null]);
    app()->forgetInstance(StripeClient::class);

    expect(fn () => app(PaymentGateway::class))->toThrow(RuntimeException::class, 'STRIPE_SECRET');
});

it('resumes an open PaymentIntent by re-reading it, client secret included', function () {
    $http = new RecordingStripeHttpClient(['id' => 'pi_abc', 'object' => 'payment_intent', 'client_secret' => 'pi_abc_secret_xyz', 'status' => 'requires_payment_method']);
    ApiRequestor::setHttpClient($http);
    $payment = Order::factory()->create()->payments()->create([
        'gateway' => 'stripe', 'transaction_id' => 'pi_abc', 'status' => 'pending', 'amount_cents' => 100, 'currency' => 'USD',
    ]);

    $intent = (new StripeGateway(new StripeClient('sk_test_dummy')))->resumeIntent($payment);

    expect($intent?->id)->toBe('pi_abc')
        ->and($intent?->clientSecret)->toBe('pi_abc_secret_xyz')
        ->and($intent?->requiresClientAction)->toBeTrue()
        ->and($http->requests[0]['method'])->toBe('get')
        ->and($http->requests[0]['url'])->toEndWith('/v1/payment_intents/pi_abc');
});

it('reports a canceled PaymentIntent as not resumable', function () {
    ApiRequestor::setHttpClient(new RecordingStripeHttpClient(['id' => 'pi_abc', 'object' => 'payment_intent', 'status' => 'canceled']));
    $payment = Order::factory()->create()->payments()->create([
        'gateway' => 'stripe', 'transaction_id' => 'pi_abc', 'status' => 'pending', 'amount_cents' => 100, 'currency' => 'USD',
    ]);

    expect((new StripeGateway(new StripeClient('sk_test_dummy')))->resumeIntent($payment))->toBeNull();
});
