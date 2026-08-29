<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Livewire\Volt\Volt;

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

it('searches the catalog', function () {
    Product::factory()->create(['title' => 'Cordless Widget']);
    Product::factory()->create(['title' => 'Garden Hose']);

    Volt::test('pages.catalog.index')
        ->set('q', 'widget')
        ->assertSee('Cordless Widget')
        ->assertDontSee('Garden Hose');
});

it('filters the catalog by a price range entered in dollars', function () {
    Product::factory()->create(['title' => 'Cheap Mug', 'price_cents' => 2500]);
    Product::factory()->create(['title' => 'Fancy Lamp', 'price_cents' => 9900]);

    Volt::test('pages.catalog.index')
        ->set('max_price', '50')
        ->assertSee('Cheap Mug')
        ->assertDontSee('Fancy Lamp')
        ->set('min_price', '30')
        ->assertDontSee('Cheap Mug');
});

it('filters the catalog by category and stock', function () {
    $category = Category::factory()->create(['name' => 'Kitchen']);
    $inCategory = Product::factory()->create(['title' => 'Steel Pan']);
    $inCategory->categories()->attach($category);
    ProductVariant::factory()->for($inCategory)->create(['stock' => 0]);
    $other = Product::factory()->create(['title' => 'Wool Scarf']);
    ProductVariant::factory()->for($other)->create(['stock' => 3]);

    Volt::test('pages.catalog.index')
        ->set('category', $category->slug)
        ->assertSee('Steel Pan')
        ->assertDontSee('Wool Scarf')
        ->set('category', '')
        ->set('in_stock', true)
        ->assertSee('Wool Scarf')
        ->assertDontSee('Steel Pan');
});
