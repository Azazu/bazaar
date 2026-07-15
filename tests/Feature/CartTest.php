<?php

use App\Models\ProductVariant;
use App\Services\Cart\CartService;

beforeEach(function () {
    $this->cart = app(CartService::class);
});

it('adds a variant and tracks count and total', function () {
    $variant = ProductVariant::factory()->create(['price_cents' => 1000]);

    $this->cart->add($variant->id, 2);

    expect($this->cart->count())->toBe(2)
        ->and($this->cart->total())->toBe(2000)
        ->and($this->cart->items())->toHaveCount(1);
});

it('updates the quantity of a line', function () {
    $variant = ProductVariant::factory()->create(['price_cents' => 500]);
    $this->cart->add($variant->id);

    $this->cart->update($variant->id, 4);

    expect($this->cart->count())->toBe(4)
        ->and($this->cart->total())->toBe(2000);
});

it('removes a line from the cart', function () {
    $variant = ProductVariant::factory()->create();
    $this->cart->add($variant->id);

    $this->cart->remove($variant->id);

    expect($this->cart->items())->toBeEmpty()
        ->and($this->cart->count())->toBe(0);
});
