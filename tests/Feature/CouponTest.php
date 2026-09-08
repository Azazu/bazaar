<?php

use App\Exceptions\CouponUnavailableException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Paid;
use App\States\Order\Pending;
use App\States\Order\Processing;
use Livewire\Volt\Volt;

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

/*
 * ME-001: a coupon use is reserved at checkout on the locked row, released on cancellation,
 * consumed by fulfilment. Unpaid or repeated checkouts can't oversubscribe max_uses.
 */

function checkoutWith(User $user, ?Coupon $coupon): Order
{
    return app(CheckoutService::class)->place($user, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'standard', $coupon);
}

it('reserves the last use of a coupon for the first order and refuses the next one until it is cancelled', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 10000]);
    $coupon = Coupon::factory()->create(['value' => 10, 'max_uses' => 1]);
    app(CartService::class)->add($variant->id, 1);

    $first = checkoutWith($user, $coupon);
    expect($first->discount_cents)->toBe(1000)->and($coupon->fresh()->used_count)->toBe(1);

    // Same cart again (the cart stays until payment): the reservation is held by the pending order.
    expect(fn () => checkoutWith($user, $coupon->fresh()))->toThrow(CouponUnavailableException::class);
    expect(Order::count())->toBe(1)->and($coupon->fresh()->used_count)->toBe(1);

    // Cancelling the unpaid order gives the use back…
    app(OrderService::class)->cancel($first);
    expect($coupon->fresh()->used_count)->toBe(0);

    // …and the coupon can be applied again.
    $second = checkoutWith($user, $coupon->fresh());
    expect($second->coupon_id)->toBe($coupon->id)->and($coupon->fresh()->used_count)->toBe(1);
});

it('keeps the use once the order is paid and fulfilled, and releases it when a paid order is cancelled or sold out', function () {
    $coupon = Coupon::factory()->create(['value' => 10, 'max_uses' => 5]);
    $variant = ProductVariant::factory()->create(['price_cents' => 10000, 'stock' => 5]);

    // Fulfilled: consumed for good — a later refund does not hand the discount back.
    app(CartService::class)->add($variant->id, 1);
    $delivered = checkoutWith(User::factory()->create(), $coupon);
    pay($delivered);
    $delivered->refresh()->status->transitionTo(Processing::class);
    app(OrderService::class)->refund($delivered->fresh());
    expect($coupon->fresh()->used_count)->toBe(1);

    // Paid then cancelled before fulfilment: released.
    app(CartService::class)->add($variant->id, 1);
    $cancelled = checkoutWith(User::factory()->create(), $coupon->fresh());
    pay($cancelled);
    expect($coupon->fresh()->used_count)->toBe(2);
    app(OrderService::class)->cancel($cancelled->fresh());
    expect($coupon->fresh()->used_count)->toBe(1);

    // Sold out between checkout and payment (auto-refund path): released too.
    app(CartService::class)->add($variant->id, 1);
    $unfulfillable = checkoutWith(User::factory()->create(), $coupon->fresh());
    expect($coupon->fresh()->used_count)->toBe(2);
    $payment = app(PaymentService::class)->start($unfulfillable)->payment;
    $variant->update(['stock' => 0]);
    app(PaymentService::class)->confirm('evt_coupon_soldout', $payment->transaction_id);
    expect($unfulfillable->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($coupon->fresh()->used_count)->toBe(1);
});

it('refuses at checkout a coupon that stopped being valid after it was applied, creating no order', function () {
    $user = User::factory()->create();
    $this->actingAs($user); // the page below works on this user's account cart
    $variant = ProductVariant::factory()->create(['price_cents' => 10000]);
    $coupon = Coupon::factory()->create(['value' => 10]);
    app(CartService::class)->add($variant->id, 1);

    $coupon->update(['expires_at' => now()->subMinute()]);

    expect(fn () => checkoutWith($user, $coupon))->toThrow(CouponUnavailableException::class);
    expect(Order::count())->toBe(0)->and($coupon->fresh()->used_count)->toBe(0);

    // The page drops the coupon and shows why, keeping the buyer on the form.
    Volt::test('pages.checkout.index')
        ->set(['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US', 'shipping_method' => 'standard', 'appliedCode' => $coupon->code])
        ->call('place')
        ->assertNoRedirect()
        ->assertSet('appliedCode', null)
        ->assertSee('no longer available');
    expect(Order::count())->toBe(0);
});

it('computes the discount from the locked coupon row, not from the copy the buyer applied', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 10000]);
    $applied = Coupon::factory()->create(['value' => 10]); // 10% when the buyer typed the code…
    app(CartService::class)->add($variant->id, 1);

    Coupon::whereKey($applied->id)->update(['value' => 500, 'type' => 'fixed']); // …edited by an admin since: 5.00 fixed

    $order = checkoutWith($user, $applied); // the stale instance still says 10% / percent

    expect($order->discount_cents)->toBe(500)
        ->and($order->total_cents)->toBe(10000 + 500 - 500)
        ->and($applied->value)->toBe(500); // the caller's instance was brought up to date as well
});

it('expires abandoned pending orders and releases their coupon reservations, leaving live ones alone', function () {
    $coupon = Coupon::factory()->create(['value' => 10, 'max_uses' => 10]);
    $variant = ProductVariant::factory()->create(['price_cents' => 10000, 'stock' => 50]);
    $place = function () use ($variant, $coupon) {
        app(CartService::class)->clear(); // the guest cart survives checkout; start each order from one unit
        app(CartService::class)->add($variant->id, 1);

        return checkoutWith(User::factory()->create(), $coupon->fresh());
    };

    $abandoned = $place();                       // never paid, two days old
    $failedAttempt = $place();                   // card declined two days ago, never retried
    $fresh = $place();                           // placed just now
    $midPayment = $place();                      // old order, but the buyer started paying a minute ago
    $paid = $place();                            // old and paid: not pending, untouched
    Order::whereKey([$abandoned->id, $failedAttempt->id, $midPayment->id, $paid->id])->update(['created_at' => now()->subDays(2)]);
    app(PaymentService::class)->fail(app(PaymentService::class)->start($failedAttempt)->payment->transaction_id);
    app(PaymentService::class)->start($midPayment);
    pay($paid);
    expect($coupon->fresh()->used_count)->toBe(5);

    $this->artisan('orders:expire-pending')->expectsOutputToContain('Expired 2 pending order(s)')->assertSuccessful();

    expect($abandoned->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($failedAttempt->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($fresh->fresh()->status)->toBeInstanceOf(Pending::class)
        ->and($midPayment->fresh()->status)->toBeInstanceOf(Pending::class)
        ->and($paid->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($coupon->fresh()->used_count)->toBe(3)                 // two reservations released
        ->and($variant->fresh()->stock)->toBe(49);                   // only the paid order ever took stock

    // Second run: nothing left to expire; the schedule carries the command.
    $this->artisan('orders:expire-pending')->expectsOutputToContain('Expired 0 pending order(s)');
    $this->artisan('schedule:list')->expectsOutputToContain('orders:expire-pending');
});
