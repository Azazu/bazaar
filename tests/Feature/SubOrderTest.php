<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;

it('splits an order into one sub-order per store', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    $variantA = ProductVariant::factory()->for(Product::factory()->for($storeA))->create(['price_cents' => 1000]);
    $variantB = ProductVariant::factory()->for(Product::factory()->for($storeB))->create(['price_cents' => 2000]);

    $cart = app(CartService::class);
    $cart->add($variantA->id, 2); // store A subtotal: 2000
    $cart->add($variantB->id, 1); // store B subtotal: 2000

    $order = app(CheckoutService::class)->place(User::factory()->create(), [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'standard');

    $subA = $order->subOrders->firstWhere('store_id', $storeA->id);
    $subB = $order->subOrders->firstWhere('store_id', $storeB->id);

    expect($order->subOrders)->toHaveCount(2)
        ->and($subA->subtotal_cents)->toBe(2000)
        ->and($subB->subtotal_cents)->toBe(2000)
        ->and($subA->items)->toHaveCount(1)
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->every(fn ($i) => $i->sub_order_id !== null))->toBeTrue();
});
