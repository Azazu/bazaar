<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Livewire\Volt\Volt;

/** Give a user a paid order containing the variant (so they qualify to review). */
function paidPurchase(User $user, ProductVariant $variant): void
{
    $order = Order::factory()->create(['buyer_id' => $user->id, 'status' => 'paid']);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_title' => $variant->product->title,
        'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents,
        'qty' => 1,
    ]);
}

it('averages only approved reviews', function () {
    $product = Product::factory()->create();
    Review::factory()->for($product, 'reviewable')->create(['rating' => 5]);
    Review::factory()->for($product, 'reviewable')->create(['rating' => 3]);
    Review::factory()->pending()->for($product, 'reviewable')->create(['rating' => 1]);

    expect($product->averageRating())->toBe(4.0)
        ->and($product->reviews()->approved()->count())->toBe(2);
});

it('detects whether a user purchased the product', function () {
    $variant = ProductVariant::factory()->create();
    $buyer = User::factory()->create();
    paidPurchase($buyer, $variant);

    expect($variant->product->purchasedBy($buyer))->toBeTrue()
        ->and($variant->product->purchasedBy(User::factory()->create()))->toBeFalse();
});

it('lets a buyer submit a review pending moderation', function () {
    $variant = ProductVariant::factory()->create();
    $buyer = User::factory()->create();
    paidPurchase($buyer, $variant);

    $this->actingAs($buyer);

    Volt::test('pages.catalog.show', ['product' => $variant->product])
        ->set('rating', 4)
        ->set('body', 'Solid product')
        ->call('submitReview')
        ->assertHasNoErrors();

    expect(Review::where('user_id', $buyer->id)->where('approved', false)->count())->toBe(1);
});
