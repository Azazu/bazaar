<?php

use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;

it('computes a percentage discount', function () {
    $coupon = Coupon::factory()->create(['value' => 10]); // 10% off

    expect($coupon->discountFor(10000))->toBe(1000);
});

it('computes a fixed discount capped at the subtotal', function () {
    $coupon = Coupon::factory()->fixed(500)->create();

    expect($coupon->discountFor(10000))->toBe(500)
        ->and($coupon->discountFor(300))->toBe(300); // never more than the subtotal
});

it('rejects an expired coupon', function () {
    expect(Coupon::factory()->expired()->create()->isValidFor(10000))->toBeFalse();
});

it('enforces a minimum subtotal', function () {
    $coupon = Coupon::factory()->create(['min_subtotal_cents' => 5000]);

    expect($coupon->isValidFor(4999))->toBeFalse()
        ->and($coupon->isValidFor(5000))->toBeTrue();
});

it('applies a coupon at checkout and records usage', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 10000]);
    $coupon = Coupon::factory()->create(['value' => 10]);

    app(CartService::class)->add($variant->id, 1);

    $order = app(CheckoutService::class)->place($user, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'standard', $coupon);

    expect($order->discount_cents)->toBe(1000)
        ->and($order->total_cents)->toBe(9500) // 10000 + 500 shipping - 1000 discount
        ->and($order->coupon_id)->toBe($coupon->id)
        ->and($coupon->fresh()->used_count)->toBe(1);
});
