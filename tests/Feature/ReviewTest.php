<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\SubOrder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

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

/*
 * ME-003: the right to review appears at payment and survives fulfilment; it never exists
 * for an order that was not paid, or whose money went back.
 */

/** A one-line order for $variant by $buyer in the given parent (and, optionally, sub-order) state. */
function purchaseIn(User $buyer, ProductVariant $variant, string $orderStatus, ?string $subOrderStatus = null): Order
{
    $order = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => $orderStatus]);
    $subOrder = $subOrderStatus === null ? null : SubOrder::factory()->create([
        'order_id' => $order->id, 'store_id' => $variant->product->store_id, 'status' => $subOrderStatus,
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id, 'sub_order_id' => $subOrder?->id, 'sku' => $variant->sku,
        'product_title' => $variant->product->title, 'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents, 'qty' => 1,
    ]);

    return $order;
}

it('counts a purchase in every state where the money was taken and kept', function (string $status) {
    $buyer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    purchaseIn($buyer, $variant, $status);

    expect($variant->product->purchasedBy($buyer))->toBeTrue()
        ->and($buyer->can('create', [Review::class, $variant->product]))->toBeTrue();
})->with(['paid', 'processing', 'shipped', 'delivered']);

it('does not count an order that was never paid or whose money went back', function (string $status) {
    $buyer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    purchaseIn($buyer, $variant, $status);

    expect($variant->product->purchasedBy($buyer))->toBeFalse()
        ->and($buyer->can('create', [Review::class, $variant->product]))->toBeFalse();
})->with(['pending', 'cancelled', 'refunded']);

it('does not count a line whose own sub-order was cancelled while the rest of the order went ahead', function () {
    $buyer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    purchaseIn($buyer, $variant, 'processing', subOrderStatus: 'cancelled');
    expect($variant->product->purchasedBy($buyer))->toBeFalse();

    purchaseIn($buyer, $variant, 'processing', subOrderStatus: 'processing');
    expect($variant->product->purchasedBy($buyer))->toBeTrue();
});

it('lets the buyer of a delivered order post a review through the API', function () {
    $buyer = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    purchaseIn($buyer, $variant, 'delivered', subOrderStatus: 'delivered');
    Sanctum::actingAs($buyer);

    $this->postJson("/api/v1/products/{$variant->product->slug}/reviews", ['rating' => 5, 'body' => 'Arrived, works.'])
        ->assertCreated();
});
