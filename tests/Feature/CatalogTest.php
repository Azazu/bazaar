<?php

use App\Models\Product;

it('shows published products on the catalog', function () {
    $product = Product::factory()->create();

    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertSee($product->title);
});

it('hides draft products from the catalog', function () {
    $product = Product::factory()->draft()->create();

    $this->get(route('catalog.index'))
        ->assertDontSee($product->title);
});

it('renders a published product page', function () {
    $product = Product::factory()->create();

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee($product->title);
});

it('returns 404 for a non-published product', function () {
    $product = Product::factory()->draft()->create();

    $this->get(route('products.show', $product))
        ->assertNotFound();
});
