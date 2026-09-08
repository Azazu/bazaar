<?php

use App\Enums\StoreStatus;
use App\Exceptions\CheckoutBlockedException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Account\AccountService;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\States\Order\Pending;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

it('places a pending order from the cart and keeps the cart until payment', function () {
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
        ->and(app(CartService::class)->count())->toBe(3); // an abandoned checkout doesn't lose the basket
});

it('removes only the purchased lines from the cart when the order is paid', function () {
    $buyer = User::factory()->create();
    $bought = ProductVariant::factory()->create(['stock' => 5]);
    $later = ProductVariant::factory()->create(['stock' => 5]);

    $this->actingAs($buyer);
    app(CartService::class)->add($bought->id, 2);
    $order = app(CheckoutService::class)->place($buyer, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'standard');
    app(CartService::class)->add($later->id); // picked after checking out

    pay($order);

    expect(app(CartService::class)->items()->pluck('variant.id')->all())->toBe([$later->id]);
});

it('lists the buyer\'s orders on the dashboard with a shortcut to pay pending ones', function () {
    $buyer = User::factory()->create();
    $mine = Order::factory()->create(['buyer_id' => $buyer->id]);
    $theirs = Order::factory()->create(); // someone else's

    $this->actingAs($buyer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('My orders')
        ->assertSee("Order #{$mine->id}")
        ->assertDontSee("Order #{$theirs->id}")
        ->assertSee('Pay now');
});

it('snapshots the line item price, name and SKU at purchase time', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 2500, 'name' => 'M / Red']);

    app(CartService::class)->add($variant->id, 1);

    $order = app(CheckoutService::class)->place($user, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'express');

    $item = $order->items->first();

    expect($item->unit_price_cents)->toBe(2500)
        ->and($item->variant_name)->toBe('M / Red')
        ->and($item->sku)->toBe($variant->sku)
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

/*
 * HI-001: checkout verifies, on locked rows, that the parties can still trade.
 */

it('refuses to place an order for a store that was archived or suspended after the cart was filled', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 1);
    $address = ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'];

    $variant->product->store->update(['status' => StoreStatus::Suspended]);
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))
        ->toThrow(CheckoutBlockedException::class, 'not selling');

    app(AccountService::class)->archiveStore($variant->product->store->fresh()->forceFill(['status' => StoreStatus::Active]));
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))
        ->toThrow(CheckoutBlockedException::class, 'no longer on the marketplace');

    expect(Order::count())->toBe(0)->and(SubOrder::count())->toBe(0);

    // Same rule, both entry points: the page shows the reason, the API answers 409.
    Volt::test('pages.checkout.index')
        ->set(['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US', 'shipping_method' => 'standard'])
        ->call('place')
        ->assertHasErrors('checkout')
        ->assertNoRedirect();

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/checkout', ['shipping_address' => $address, 'shipping_method' => 'standard'])->assertConflict();
});

it('refuses to place an order for an account that has been closed', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 1);
    $address = ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'];

    app(AccountService::class)->close($user);

    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))
        ->toThrow(CheckoutBlockedException::class, 'closed');
    expect(Order::count())->toBe(0);
});
