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
    $this->getJson('/api/v1/products?sort=bogus&per_page=500&min_rating=9')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort', 'per_page', 'min_rating']);
});

it('accepts an upper price bound on its own but rejects an inverted range', function () {
    $this->getJson('/api/v1/products?max_price=5000')->assertOk();

    $this->getJson('/api/v1/products?min_price=9000&max_price=5000')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_price');
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

it('searches products by title or description', function () {
    $byTitle = Product::factory()->create(['title' => 'Blue Widget Deluxe', 'description' => 'Nothing to see']);
    $byDescription = Product::factory()->create(['title' => 'Plain thing', 'description' => 'A widget for every home']);
    Product::factory()->create(['title' => 'Gadget', 'description' => 'Unrelated']);
    Product::factory()->draft()->create(['title' => 'Widget draft']);

    $this->getJson('/api/v1/products?q=widget')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.*.id', fn (array $ids) => collect($ids)->sort()->values()->all()
            === collect([$byTitle->id, $byDescription->id])->sort()->values()->all());
});

it('combines search with facets and keeps pagination exact', function () {
    $category = Category::factory()->create();
    $cheap = Product::factory()->create(['title' => 'Widget A', 'price_cents' => 1000]);
    $pricey = Product::factory()->create(['title' => 'Widget B', 'price_cents' => 9000]);
    $category->products()->attach([$cheap->id, $pricey->id]);
    Product::factory()->create(['title' => 'Widget C', 'price_cents' => 1000]); // not in the category

    $this->getJson("/api/v1/products?q=widget&category={$category->slug}&max_price=5000&per_page=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cheap->id)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.last_page', 1);
});

it('keeps the search term in pagination links without Scout\'s internal param', function () {
    Product::factory()->count(2)->create(['title' => 'Widget']);

    $next = $this->getJson('/api/v1/products?q=widget&per_page=1')->assertOk()->json('links.next');

    expect($next)->toContain('q=widget')->not->toContain('query=');
});

it('filters by minimum rating using approved reviews only', function () {
    $good = Product::factory()->create();
    Review::factory()->for($good, 'reviewable')->create(['rating' => 5]);
    Review::factory()->for($good, 'reviewable')->create(['rating' => 4]);
    $meh = Product::factory()->create();
    Review::factory()->for($meh, 'reviewable')->create(['rating' => 2]);
    $unapproved = Product::factory()->create();
    Review::factory()->pending()->for($unapproved, 'reviewable')->create(['rating' => 5]);

    $this->getJson('/api/v1/products?min_rating=4')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $good->id)
        ->assertJsonPath('data.0.rating.average', 4.5);
});

it('exposes the variant price range on list items', function () {
    $product = Product::factory()->create(['price_cents' => 1000]);
    ProductVariant::factory()->for($product)->create(['price_cents' => 4000]);
    ProductVariant::factory()->for($product)->create(['price_cents' => 18000]);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.price_range.min_cents', 4000)
        ->assertJsonPath('data.0.price_range.max_cents', 18000)
        ->assertJsonPath('data.0.price_range.label', '$40.00 – $180.00');
});
