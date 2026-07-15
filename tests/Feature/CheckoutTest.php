<?php

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\States\Order\Pending;

it('places a pending order from the cart and clears it', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 1000]);

    app(CartService::class)->add($variant->id, 3);

    $order = app(CheckoutService::class)->place($user, [
        'name' => 'Jane Doe',
        'line1' => '1 Main St',
        'city' => 'Springfield',
        'postcode' => '12345',
        'country' => 'US',
    ], 'standard');

    expect($order->buyer_id)->toBe($user->id)
        ->and($order->status)->toBeInstanceOf(Pending::class)
        ->and($order->items)->toHaveCount(1)
        ->and($order->subtotal_cents)->toBe(3000)
        ->and($order->shipping_cents)->toBe(500)
        ->and($order->total_cents)->toBe(3500)
        ->and(app(CartService::class)->items())->toBeEmpty();
});

it('snapshots the line item price and name at purchase time', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 2500, 'name' => 'M / Red']);

    app(CartService::class)->add($variant->id, 1);

    $order = app(CheckoutService::class)->place($user, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'express');

    $item = $order->items->first();

    expect($item->unit_price_cents)->toBe(2500)
        ->and($item->variant_name)->toBe('M / Red')
        ->and($order->shipping_cents)->toBe(1500);
});

it('lets a buyer view their own order', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['buyer_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('orders.show', $order))
        ->assertOk();
});

it('forbids viewing another buyer\'s order', function () {
    $order = Order::factory()->create(['buyer_id' => User::factory()->create()->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order))
        ->assertForbidden();
});
