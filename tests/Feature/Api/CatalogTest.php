<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;

it('lists only published products', function () {
    $published = Product::factory()->create();
    Product::factory()->draft()->create();

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $published->slug)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('filters by category, price and stock', function () {
    $category = Category::factory()->create();
    $inCategory = Product::factory()->create(['price_cents' => 5000]);
    $inCategory->categories()->attach($category);
    Product::factory()->create(['price_cents' => 5000]);

    $this->getJson("/api/v1/products?category={$category->slug}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inCategory->id);

    $this->getJson('/api/v1/products?min_price=6000')->assertOk()->assertJsonCount(0, 'data');

    ProductVariant::factory()->for($inCategory)->create(['stock' => 3]);
    $this->getJson('/api/v1/products?in_stock=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inCategory->id);
});

it('sorts by price', function () {
    Product::factory()->create(['price_cents' => 3000]);
    Product::factory()->create(['price_cents' => 1000]);

    $this->getJson('/api/v1/products?sort=price_asc')
        ->assertJsonPath('data.0.price_cents', 1000)
        ->assertJsonPath('data.1.price_cents', 3000);
});

it('rejects unknown filter values', function () {
    $this->getJson('/api/v1/products?sort=bogus&per_page=500')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort', 'per_page']);
});

it('shows a published product with variants, store and rating', function () {
    $product = Product::factory()->create(['price_cents' => 1999]);
    ProductVariant::factory()->count(2)->for($product)->create();
    Review::factory()->for($product, 'reviewable')->create(['rating' => 5]);
    Review::factory()->for($product, 'reviewable')->create(['rating' => 4]);
    Review::factory()->pending()->for($product, 'reviewable')->create(['rating' => 1]); // ignored

    $this->getJson("/api/v1/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.price_cents', 1999)
        ->assertJsonPath('data.price', '$19.99')
        ->assertJsonPath('data.store.id', $product->store_id)
        ->assertJsonPath('data.rating.average', 4.5)
        ->assertJsonPath('data.rating.count', 2)
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonMissingPath('data.store.owner_id');
});

it('returns 404 for a draft product', function () {
    $product = Product::factory()->draft()->create();

    $this->getJson("/api/v1/products/{$product->slug}")->assertNotFound();
});

it('lists the category tree', function () {
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.children');
});
