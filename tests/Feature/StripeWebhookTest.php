<?php

use App\Models\Order;
use App\Models\ProductVariant;
use App\States\Order\Paid;
use App\States\Order\Pending;

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(fn () => config(['services.stripe.webhook_secret' => WEBHOOK_SECRET]));

/** Sign a payload the way Stripe does: t=<ts>,v1=HMAC-SHA256(secret, "<ts>.<payload>"). */
function stripeSignature(string $payload, string $secret = WEBHOOK_SECRET, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
}

/** @return array{0: Order, 1: ProductVariant} a pending order with a started Stripe payment */
function orderAwaitingStripe(string $intentId = 'pi_test_123'): array
{
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 2);
    $order->payments()->create([
        'gateway' => 'stripe',
        'transaction_id' => $intentId,
        'status' => 'pending',
        'amount_cents' => $order->total_cents,
        'currency' => 'USD',
    ]);

    return [$order, $variant];
}

function stripeEvent(string $type, string $intentId, string $eventId = 'evt_test_1'): string
{
    return json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent']],
    ], JSON_THROW_ON_ERROR);
}

it('marks the order paid on a signed payment_intent.succeeded event', function () {
    [$order, $variant] = orderAwaitingStripe();
    $payload = stripeEvent('payment_intent.succeeded', 'pi_test_123');

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertNoContent();

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($variant->fresh()->stock)->toBe(3)
        ->and($order->payments()->value('status'))->toBe('succeeded');
});

it('rejects a payload with a bad signature and changes nothing', function () {
    [$order] = orderAwaitingStripe();
    $payload = stripeEvent('payment_intent.succeeded', 'pi_test_123');

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_wrong'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    expect($order->fresh()->status)->toBeInstanceOf(Pending::class);
});

it('rejects a request without a signature', function () {
    $this->postJson('/stripe/webhook', ['type' => 'payment_intent.succeeded'])->assertStatus(400);
});

it('is idempotent across redelivery of the same event', function () {
    [$order, $variant] = orderAwaitingStripe();
    $payload = stripeEvent('payment_intent.succeeded', 'pi_test_123', 'evt_dup');
    $headers = ['HTTP_STRIPE_SIGNATURE' => stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'];

    $this->call('POST', '/stripe/webhook', [], [], [], $headers, $payload)->assertNoContent();
    $this->call('POST', '/stripe/webhook', [], [], [], $headers, $payload)->assertNoContent();

    expect($variant->fresh()->stock)->toBe(3); // decremented once
});

it('records a failed attempt and leaves the order payable', function () {
    [$order] = orderAwaitingStripe();
    $payload = stripeEvent('payment_intent.payment_failed', 'pi_test_123');

    $this->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertNoContent();

    expect($order->payments()->value('status'))->toBe('failed')
        ->and($order->fresh()->status)->toBeInstanceOf(Pending::class);
});

it('acknowledges events for unknown intents and unrelated types without failing', function () {
    foreach ([
        stripeEvent('payment_intent.succeeded', 'pi_never_created'),
        stripeEvent('charge.refunded', 'ch_whatever'),
    ] as $payload) {
        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertNoContent();
    }
});
