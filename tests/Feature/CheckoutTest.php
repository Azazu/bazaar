<?php

use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Events\OrderPaid;
use App\Exceptions\CheckoutBlockedException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Account\AccountService;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\States\Order\Pending;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;

it('places a pending order from the cart and keeps the cart until payment', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 1000]);

    app(CartService::class)->add($variant->id, 3);

    $order = app(CheckoutService::class)->place($user, [
        'name' => 'Jane Doe',
        'line1' => '1 Main St',
        'city' => 'Springfield',
        'postcode' => '12345',
        'country' => 'US',
    ], 'standard');

    expect($order->buyer_id)->toBe($user->id)
        ->and($order->status)->toBeInstanceOf(Pending::class)
        ->and($order->items)->toHaveCount(1)
        ->and($order->subtotal_cents)->toBe(3000)
        ->and($order->shipping_cents)->toBe(500)
        ->and($order->total_cents)->toBe(3500)
        ->and(app(CartService::class)->count())->toBe(3); // an abandoned checkout doesn't lose the basket
});

it('removes only the purchased quantities from the cart when the order is paid', function () {
    $buyer = User::factory()->create();
    $bought = ProductVariant::factory()->create(['stock' => 5]);
    $later = ProductVariant::factory()->create(['stock' => 5]);

    $this->actingAs($buyer);
    app(CartService::class)->add($bought->id, 2);
    $order = app(CheckoutService::class)->place($buyer, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'standard');
    app(CartService::class)->add($bought->id, 1); // one more of the *same* variant, after checking out
    app(CartService::class)->add($later->id);     // and something else

    pay($order);

    expect(app(CartService::class)->lines())->toBe([$bought->id => 1, $later->id => 1]);

    // A replayed OrderPaid for the same order must not subtract the two units again.
    OrderPaid::dispatch($order->fresh());
    expect(app(CartService::class)->lines())->toBe([$bought->id => 1, $later->id => 1]);
});

it('lists the buyer\'s orders on the dashboard with a shortcut to pay pending ones', function () {
    $buyer = User::factory()->create();
    $mine = Order::factory()->create(['buyer_id' => $buyer->id]);
    $theirs = Order::factory()->create(); // someone else's

    $this->actingAs($buyer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('My orders')
        ->assertSee("Order #{$mine->id}")
        ->assertDontSee("Order #{$theirs->id}")
        ->assertSee('Pay now');
});

it('snapshots the line item price, name and SKU at purchase time', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create(['price_cents' => 2500, 'name' => 'M / Red']);

    app(CartService::class)->add($variant->id, 1);

    $order = app(CheckoutService::class)->place($user, [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'express');

    $item = $order->items->first();

    expect($item->unit_price_cents)->toBe(2500)
        ->and($item->variant_name)->toBe('M / Red')
        ->and($item->sku)->toBe($variant->sku)
        ->and($order->shipping_cents)->toBe(1500);
});

it('lets a buyer view their own order', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['buyer_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('orders.show', $order))
        ->assertOk();
});

it('forbids viewing another buyer\'s order', function () {
    $order = Order::factory()->create(['buyer_id' => User::factory()->create()->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('orders.show', $order))
        ->assertForbidden();
});

/*
 * HI-001: checkout verifies, on locked rows, that the parties can still trade.
 */

it('refuses to place an order for a store that was archived or suspended after the cart was filled', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 1);
    $address = ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'];

    $variant->product->store->update(['status' => StoreStatus::Suspended]);
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))
        ->toThrow(CheckoutBlockedException::class, 'not selling');

    app(AccountService::class)->archiveStore($variant->product->store->fresh()->forceFill(['status' => StoreStatus::Active]));
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))
        ->toThrow(CheckoutBlockedException::class, 'no longer on the marketplace');

    expect(Order::count())->toBe(0)->and(SubOrder::count())->toBe(0);

    // Same rule, both entry points: the page shows the reason, the API answers 409.
    Volt::test('pages.checkout.index')
        ->set(['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US', 'shipping_method' => 'standard'])
        ->call('place')
        ->assertHasErrors('checkout')
        ->assertNoRedirect();

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/checkout', ['shipping_address' => $address, 'shipping_method' => 'standard'])->assertConflict();
});

it('refuses to place an order for an account that has been closed', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 1);
    $address = ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'];

    app(AccountService::class)->close($user);

    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))
        ->toThrow(CheckoutBlockedException::class, 'closed');
    expect(Order::count())->toBe(0);
});

/*
 * HI-003: the order is built from one locked read inside the transaction, and every line is
 * re-checked for sellability there, whatever the cart endpoints let through.
 */

it('keeps subtotal equal to the sum of the snapshot lines when a price changes mid-checkout', function () {
    $user = User::factory()->create();
    $a = ProductVariant::factory()->create(['price_cents' => 1000]);
    $b = ProductVariant::factory()->create(['price_cents' => 2000]);
    $this->actingAs($user);
    app(CartService::class)->add($a->id, 2);
    app(CartService::class)->add($b->id, 1);

    // A vendor raises the price the instant the first variant row is read. With separate reads
    // for "lines" and "total" the snapshot and the subtotal would disagree; one read can't.
    $raised = false;
    ProductVariant::retrieved(function () use ($b, &$raised) {
        if (! $raised) {
            $raised = true;
            ProductVariant::whereKey($b->id)->update(['price_cents' => 9999]);
        }
    });

    $order = app(CheckoutService::class)->place($user, ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'], 'standard');

    $lines = $order->items->sum(fn ($item) => $item->unit_price_cents * $item->qty);
    expect($order->subtotal_cents)->toBe($lines)
        ->and($order->total_cents)->toBe($lines + CheckoutService::SHIPPING_RATES['standard'])
        ->and($order->subOrders->sum('subtotal_cents'))->toBe($lines);
});

it('refuses to check out a draft product, a variant of a non-active store, or a variant that no longer exists', function () {
    $user = User::factory()->create();
    $address = ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'];
    $this->actingAs($user);

    // Draft product: the cart endpoint would have refused it, but a stale cart (or a product
    // unpublished after adding) must be caught at checkout regardless.
    $draft = ProductVariant::factory()->create();
    app(CartService::class)->add($draft->id, 1);
    $draft->product->update(['status' => ProductStatus::Draft]);
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))->toThrow(CheckoutBlockedException::class, 'no longer for sale');
    app(CartService::class)->remove($draft->id);

    // Store pending moderation.
    $pending = ProductVariant::factory()->create();
    app(CartService::class)->add($pending->id, 1);
    $pending->product->store->update(['status' => StoreStatus::Pending]);
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))->toThrow(CheckoutBlockedException::class, 'not selling');
    app(CartService::class)->remove($pending->id);

    // Variant deleted while it sat in the cart: it must not silently vanish from the order.
    $gone = ProductVariant::factory()->create();
    $kept = ProductVariant::factory()->create();
    app(CartService::class)->add($gone->id, 1);
    app(CartService::class)->add($kept->id, 1);
    ProductVariant::whereKey($gone->id)->delete();
    expect(fn () => app(CheckoutService::class)->place($user, $address, 'standard'))->toThrow(CheckoutBlockedException::class, 'no longer available');

    expect(Order::count())->toBe(0)->and(SubOrder::count())->toBe(0);
});

it('gives the web page and the API the same answer for an unsellable cart', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 1);
    $variant->product->update(['status' => ProductStatus::Draft]);

    Volt::test('pages.checkout.index')
        ->set(['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US', 'shipping_method' => 'standard'])
        ->call('place')
        ->assertHasErrors('checkout')
        ->assertSee('no longer for sale');

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/checkout', [
        'shipping_address' => ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'],
        'shipping_method' => 'standard',
    ])->assertConflict()->assertJsonPath('message', fn (string $m) => str_contains($m, 'no longer for sale'));

    expect(Order::count())->toBe(0);
});

it('snapshots the line from the locked product row, not a separate read', function () {
    // The product is unpublished the instant the variant row is read (after the locked product
    // read). Checkout must not see the stale relation as "published" — it reads the locked row.
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 1);

    ProductVariant::retrieved(fn (ProductVariant $v) => Product::whereKey($v->product_id)->update(['status' => ProductStatus::Draft]));

    // In SQLite there are no row locks, so the write lands: the outcome that matters is
    // that the decision is made from the row read inside the transaction — here still published,
    // because products were read before variants — and the order carries that snapshot.
    $order = app(CheckoutService::class)->place($user, ['name' => 'A', 'line1' => '1', 'city' => 'C', 'postcode' => '0', 'country' => 'US'], 'standard');

    expect($order->items->first()->product_title)->toBe($variant->product->title);
});
