<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists only approved reviews', function () {
    $product = Product::factory()->create();
    Review::factory()->for($product, 'reviewable')->create(['rating' => 5]);
    Review::factory()->pending()->for($product, 'reviewable')->create();

    $this->getJson("/api/v1/products/{$product->slug}/reviews")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonStructure(['data' => [['id', 'rating', 'body', 'author', 'created_at']]]);
});

it('lets a buyer post a review that awaits moderation', function () {
    $variant = ProductVariant::factory()->create();
    $buyer = User::factory()->create();
    paidPurchase($buyer, $variant);
    Sanctum::actingAs($buyer);

    $this->postJson("/api/v1/products/{$variant->product->slug}/reviews", ['rating' => 4, 'body' => 'Solid'])
        ->assertCreated()
        ->assertJsonPath('data.approved', false)
        ->assertJsonPath('data.author', $buyer->name);

    expect(Review::where('user_id', $buyer->id)->where('approved', false)->count())->toBe(1);
});

it('forbids reviews from users who did not buy the product', function () {
    $product = Product::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/products/{$product->slug}/reviews", ['rating' => 5])->assertForbidden();
});

it('allows only one review per buyer per product', function () {
    $variant = ProductVariant::factory()->create();
    $buyer = User::factory()->create();
    paidPurchase($buyer, $variant);
    Sanctum::actingAs($buyer);

    $this->postJson("/api/v1/products/{$variant->product->slug}/reviews", ['rating' => 5])->assertCreated();
    $this->postJson("/api/v1/products/{$variant->product->slug}/reviews", ['rating' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rating');
});

it('validates the rating range', function () {
    $variant = ProductVariant::factory()->create();
    $buyer = User::factory()->create();
    paidPurchase($buyer, $variant);
    Sanctum::actingAs($buyer);

    $this->postJson("/api/v1/products/{$variant->product->slug}/reviews", ['rating' => 6])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rating');
});
