<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\SubOrder;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderCancelledNotice;
use App\Notifications\OrderConfirmed;
use App\Notifications\OrderRefundedNotice;
use App\Notifications\SubOrderStatusUpdated;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\States\SubOrder\Paid;
use App\States\SubOrder\Processing;
use App\States\SubOrder\Shipped;
use Illuminate\Support\Facades\Notification;

it('notifies the buyer when the order is paid', function () {
    Notification::fake();

    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = Order::factory()->create([
        'subtotal_cents' => $variant->price_cents,
        'shipping_cents' => 0,
        'total_cents' => $variant->price_cents,
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_title' => $variant->product->title,
        'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents,
        'qty' => 1,
    ]);

    $payment = app(PaymentService::class)->start($order)->payment;
    app(PaymentService::class)->confirm('evt_notif', $payment->transaction_id);

    Notification::assertSentTo($order->buyer, OrderConfirmed::class);
});

it('tells each store owner about their part of a paid order', function () {
    Notification::fake();

    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $variantA = ProductVariant::factory()->for(Product::factory()->for($storeA))->create(['stock' => 5]);
    $variantB = ProductVariant::factory()->for(Product::factory()->for($storeB))->create(['stock' => 5]);

    $cart = app(CartService::class);
    $cart->add($variantA->id);
    $cart->add($variantB->id);
    $order = app(CheckoutService::class)->place(User::factory()->create(), [
        'name' => 'A', 'line1' => 'B', 'city' => 'C', 'postcode' => '12345', 'country' => 'US',
    ], 'standard');

    pay($order);

    Notification::assertSentTo($storeA->owner, NewOrderReceived::class,
        fn ($n) => $n->subOrder->store_id === $storeA->id);
    Notification::assertSentTo($storeB->owner, NewOrderReceived::class,
        fn ($n) => $n->subOrder->store_id === $storeB->id);
    Notification::assertSentToTimes($storeA->owner, NewOrderReceived::class, 1);
});

it('tells the buyer when a vendor moves their sub-order forward, but not on payment', function () {
    Notification::fake();

    $order = Order::factory()->paid()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id]);

    $sub->status->transitionTo(Paid::class);
    Notification::assertNothingSentTo($order->buyer);

    $sub->status->transitionTo(Processing::class);
    $sub->status->transitionTo(Shipped::class);

    // Each mail carries the state it was sent for, even though the model has moved on since.
    Notification::assertSentToTimes($order->buyer, SubOrderStatusUpdated::class, 2);
    Notification::assertSentTo($order->buyer, SubOrderStatusUpdated::class,
        fn ($n) => $n->status instanceof Processing);
    Notification::assertSentTo($order->buyer, SubOrderStatusUpdated::class,
        fn ($n) => $n->status instanceof Shipped);
});

it('tells the buyer about a cancellation, mentioning the refund only if they had paid', function () {
    Notification::fake();

    $pending = Order::factory()->create();
    app(OrderService::class)->cancel($pending);
    Notification::assertSentTo($pending->buyer, OrderCancelledNotice::class, fn ($n) => $n->refunded === false);

    $paid = Order::factory()->paid()->create();
    app(OrderService::class)->cancel($paid);
    Notification::assertSentTo($paid->buyer, OrderCancelledNotice::class, fn ($n) => $n->refunded === true);
});

it('tells the buyer about a refund', function () {
    Notification::fake();

    $order = Order::factory()->paid()->create();
    app(OrderService::class)->refund($order);

    Notification::assertSentTo($order->buyer, OrderRefundedNotice::class);
});

it('renders the vendor mail with the items of that store only', function () {
    $sub = SubOrder::factory()->create(['subtotal_cents' => 2500]);
    $sub->items()->create([
        'order_id' => $sub->order_id, 'product_title' => 'Blue Mug', 'variant_name' => 'L',
        'unit_price_cents' => 2500, 'qty' => 1,
    ]);

    $mail = (new NewOrderReceived($sub->load('items', 'store')))->toMail($sub->store->owner);

    expect($mail->subject)->toContain("#{$sub->order_id}")
        ->and(implode("\n", $mail->introLines))->toContain('Blue Mug')->toContain('$25.00')
        ->and($mail->actionUrl)->toBe(route('vendor.orders'));
});
