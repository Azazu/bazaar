<?php

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentIntentData;
use App\States\Order\Paid;
use App\States\Order\Pending;
use Laravel\Sanctum\Sanctum;

/** @return array<string, mixed> */
function checkoutPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'shipping_address' => [
            'name' => 'Jane Doe',
            'line1' => '1 Main St',
            'city' => 'Springfield',
            'postcode' => '12345',
            'country' => 'us',
        ],
        'shipping_method' => 'standard',
    ], $overrides);
}

it('places a pending order split into per-store sub-orders and empties the cart', function () {
    Sanctum::actingAs(User::factory()->create());
    $a = ProductVariant::factory()->create(['price_cents' => 1000, 'stock' => 5]);
    $b = ProductVariant::factory()->create(['price_cents' => 2000, 'stock' => 5]); // another store
    $this->postJson('/api/v1/cart/items', ['variant_id' => $a->id, 'qty' => 2]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $b->id]);

    $this->postJson('/api/v1/checkout', checkoutPayload(['shipping_method' => 'express']))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.subtotal_cents', 4000)
        ->assertJsonPath('data.shipping_cents', 1500)
        ->assertJsonPath('data.total_cents', 5500)
        ->assertJsonPath('data.shipping_address.country', 'US')
        ->assertJsonCount(2, 'data.items')
        ->assertJsonCount(2, 'data.sub_orders');

    $this->getJson('/api/v1/cart')->assertJsonPath('data.count', 3); // cart empties on payment, not on checkout
});

it('rejects checkout with an empty cart', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/checkout', checkoutPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cart');

    expect(Order::count())->toBe(0);
});

it('validates the shipping details', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/checkout', ['shipping_method' => 'teleport'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shipping_address.name', 'shipping_address.country', 'shipping_method']);
});

it('rejects an invalid coupon instead of silently dropping it', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['price_cents' => 1000, 'stock' => 5]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id]);
    Coupon::factory()->expired()->create(['code' => 'OLD']);

    $this->postJson('/api/v1/checkout', checkoutPayload(['coupon_code' => 'OLD']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('coupon_code');
});

it('applies a valid coupon', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['price_cents' => 10000, 'stock' => 5]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id]);
    Coupon::factory()->create(['code' => 'SAVE10', 'value' => 10]);

    $this->postJson('/api/v1/checkout', checkoutPayload(['coupon_code' => 'save10']))
        ->assertCreated()
        ->assertJsonPath('data.discount_cents', 1000)
        ->assertJsonPath('data.total_cents', 9500)
        ->assertJsonPath('data.coupon_code', 'SAVE10');
});

it('pays a pending order in the sandbox and decrements stock', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['price_cents' => 1000, 'stock' => 5]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => 2]);
    $orderId = $this->postJson('/api/v1/checkout', checkoutPayload())->json('data.id');

    $this->postJson("/api/v1/orders/{$orderId}/pay")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.sub_orders.0.status', 'paid');

    expect($variant->fresh()->stock)->toBe(3);
    $this->getJson('/api/v1/cart')->assertJsonPath('data.count', 0); // purchased lines released
});

it('refuses to pay an order that is not pending', function () {
    Sanctum::actingAs($buyer = User::factory()->create());
    $order = Order::factory()->paid()->create(['buyer_id' => $buyer->id]);

    $this->postJson("/api/v1/orders/{$order->id}/pay")->assertForbidden();
});

it('reports a state conflict when an admin retries payment on a paid order', function () {
    // Admins pass every policy (Gate::before) — the service-level invariant still holds.
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
    $order = Order::factory()->paid()->create(['buyer_id' => $admin->id]);

    $this->postJson("/api/v1/orders/{$order->id}/pay")
        ->assertConflict()
        ->assertJsonStructure(['message']);
});

it('refuses to pay someone else\'s order', function () {
    Sanctum::actingAs(User::factory()->create());
    $order = Order::factory()->create();

    $this->postJson("/api/v1/orders/{$order->id}/pay")->assertForbidden();

    expect($order->fresh()->status)->toBeInstanceOf(Pending::class);
});

it('reports a stock shortfall at payment time and leaves the order pending', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['price_cents' => 1000, 'stock' => 1]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id]);
    $orderId = $this->postJson('/api/v1/checkout', checkoutPayload())->json('data.id');

    $variant->update(['stock' => 0]); // someone else bought the last unit meanwhile

    $this->postJson("/api/v1/orders/{$orderId}/pay")
        ->assertUnprocessable()
        ->assertJsonStructure(['message']);

    expect(Order::find($orderId)->status)->toBeInstanceOf(Pending::class)
        ->and(Order::find($orderId)->status)->not->toBeInstanceOf(Paid::class);
});

it('returns the client secret and keeps the order pending when the gateway needs client confirmation', function () {
    app()->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
    {
        public function name(): string
        {
            return 'stripe';
        }

        public function createIntent(Order $order): PaymentIntentData
        {
            return new PaymentIntentData('pi_test', 'pi_test_secret', requiresClientAction: true);
        }

        public function refund(Payment $payment): void {}
    });
    Sanctum::actingAs($buyer = User::factory()->create());
    $order = Order::factory()->create(['buyer_id' => $buyer->id]);

    $this->postJson("/api/v1/orders/{$order->id}/pay")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('payment.gateway', 'stripe')
        ->assertJsonPath('payment.status', 'pending')
        ->assertJsonPath('payment.client_secret', 'pi_test_secret');
});
