<?php

use App\Exceptions\CouponUnavailableException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\CartStorage;
use App\Services\Cart\CartStorageFactory;
use App\Services\Checkout\CheckoutService;

beforeEach(fn () => requiresDatabaseConcurrency());

/*
 * ME-001: six buyers hit "place order" at the same instant with a coupon that has one use
 * left. The use is taken on the locked coupon row inside each checkout transaction, so
 * exactly one order gets the discount and the others are refused, never over-redeemed.
 */

it('hands the last use of a coupon to exactly one of several simultaneous checkouts', function () {
    $coupon = Coupon::factory()->create(['value' => 10, 'max_uses' => 1]);
    $variant = ProductVariant::factory()->create(['stock' => 100, 'price_cents' => 10000]);
    $buyers = User::factory()->count(6)->create();

    $result = raceInParallel(6, function (int $i) use ($coupon, $variant, $buyers) {
        try {
            app()->bind(CartStorage::class, fn () => app(CartStorageFactory::class)->forGuest());
            app(CartService::class)->add($variant->id, 1);
            app(CheckoutService::class)->place(
                $buyers[$i - 1],
                ['name' => 'Racer', 'line1' => '1 Lock St', 'city' => 'Town', 'postcode' => '00000', 'country' => 'US'],
                'standard',
                Coupon::findOrFail($coupon->id),
            );

            return true;
        } catch (CouponUnavailableException) {
            return false;
        }
    });

    expect($result)->toBe(['ok' => 1, 'rejected' => 5, 'failed' => 0])
        ->and($coupon->fresh()->used_count)->toBe(1)
        ->and(Order::where('coupon_id', $coupon->id)->count())->toBe(1)
        ->and(Order::count())->toBe(1); // a refused coupon means no order at all, not an order without discount
});
