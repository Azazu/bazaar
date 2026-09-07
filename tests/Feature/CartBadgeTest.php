<?php

use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use Livewire\Volt\Volt;

it('shows the cart count and refreshes when the cart changes', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5]);

    $badge = Volt::test('cart-badge')->assertSee('Cart (0)');

    app(CartService::class)->add($variant->id, 2);

    $badge->dispatch('cart-updated')->assertSee('Cart (2)');
});

it('announces cart changes from the product page and the cart page', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5]);

    Volt::test('pages.catalog.show', ['product' => $variant->product])
        ->call('addToCart', $variant->id)
        ->assertDispatched('cart-updated');

    Volt::test('pages.cart.index')
        ->call('remove', $variant->id)
        ->assertDispatched('cart-updated');
});
