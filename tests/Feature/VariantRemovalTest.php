<?php

use App\Exceptions\DeletionBlockedException;
use App\Exceptions\MissingVariantException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Notifications\OrderUnfulfillableNotice;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Delivered;
use App\States\Order\Paid;
use App\States\Order\Pending;
use App\States\Order\Processing;
use App\States\Order\Shipped;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

/*
 * HI-004: a variant that disappears between checkout and payment is an unfulfillable line,
 * never a silent skip — and while an order still needs it, it can't disappear at all.
 */

it('refunds and cancels when the variant was removed between checkout and the payment webhook', function () {
    Notification::fake();
    $variant = ProductVariant::factory()->create(['stock' => 5, 'name' => 'XL / red']);
    $order = orderForVariant($variant, 1);
    $subOrder = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $variant->product->store_id]);
    $payment = app(PaymentService::class)->start($order)->payment;

    // Removed behind the model's back (a raw delete, a catalog import…): the FK nulls the line's reference.
    ProductVariant::whereKey($variant->id)->delete();
    expect($order->items()->first()->product_variant_id)->toBeNull();

    app(PaymentService::class)->confirm('evt_gone', $payment->transaction_id); // handled, no exception

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)      // never paid
        ->and($subOrder->fresh()->status)->toBeInstanceOf(App\States\SubOrder\Cancelled::class)
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($subOrder->fresh()->payout)->toBeNull();

    // The buyer is told which line, from the snapshot — the variant itself is gone.
    Notification::assertSentTo($order->buyer, OrderUnfulfillableNotice::class,
        fn ($n) => str_contains($n->soldOutItem, 'XL / red') && str_contains($n->soldOutItem, $variant->product->title));
});

it('does not become paid when one of several lines can no longer be confirmed', function () {
    $kept = ProductVariant::factory()->create(['stock' => 5]);
    $gone = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($kept, 1);
    $order->items()->create(['product_variant_id' => $gone->id, 'sku' => $gone->sku, 'product_title' => 'Gone', 'variant_name' => 'X', 'unit_price_cents' => 100, 'qty' => 1]);
    $payment = app(PaymentService::class)->start($order->load('items'))->payment;

    ProductVariant::whereKey($gone->id)->delete();
    app(PaymentService::class)->confirm('evt_partial', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($kept->fresh()->stock)->toBe(5); // the deliverable line was not decremented either: all or nothing
});

it('refuses to start a payment for an order whose variant is gone, and says so on the order page', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5, 'name' => 'XL / red']);
    $order = orderForVariant($variant, 1);
    ProductVariant::whereKey($variant->id)->delete();

    expect(fn () => app(PaymentService::class)->start($order->fresh()))->toThrow(MissingVariantException::class);

    $this->actingAs($order->buyer);
    Volt::test('pages.orders.show', ['order' => $order->fresh()])
        ->call('pay')
        ->assertHasNoErrors()
        ->assertSee('XL / red')
        ->assertSee('no longer available');

    expect($order->fresh()->status)->toBeInstanceOf(Pending::class);
});

it('refuses to remove a variant or its product while an order still needs it', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 1);
    pay($order);
    expect($order->fresh()->status)->toBeInstanceOf(Paid::class);

    expect(fn () => $variant->delete())->toThrow(DeletionBlockedException::class);
    expect(fn () => $variant->product->delete())->toThrow(DeletionBlockedException::class);
    expect(ProductVariant::find($variant->id))->not->toBeNull()
        ->and(Product::find($variant->product_id))->not->toBeNull();
});

it('lets the catalog be cleaned up once the order is finished, and the order stays readable from its snapshot', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5, 'name' => 'XL / red']);
    $order = orderForVariant($variant, 1);
    pay($order);
    $order->fresh()->status->transitionTo(Processing::class);
    $order->fresh()->status->transitionTo(Shipped::class);
    $order->fresh()->status->transitionTo(Delivered::class);
    $title = $variant->product->title;

    $variant->product->delete(); // allowed now; cascades to the variant

    $item = OrderItem::where('order_id', $order->id)->firstOrFail();
    expect(ProductVariant::find($variant->id))->toBeNull()
        ->and($item->product_variant_id)->toBeNull()
        ->and($item->product_title)->toBe($title)
        ->and($item->variant_name)->toBe('XL / red')
        ->and($item->sku)->toBe($variant->sku);

    $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertOk()->assertSee($title)->assertSee('XL / red');
    $this->actingAs(admin())->get("/admin/orders/{$order->id}")->assertOk()->assertSee($title)->assertSee($variant->sku);
});
