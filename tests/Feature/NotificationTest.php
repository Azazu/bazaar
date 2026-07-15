<?php

use App\Models\Order;
use App\Models\ProductVariant;
use App\Notifications\OrderConfirmed;
use App\Services\Payment\PaymentService;
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

    $payment = app(PaymentService::class)->start($order);
    app(PaymentService::class)->confirm('evt_notif', $payment->transaction_id);

    Notification::assertSentTo($order->buyer, OrderConfirmed::class);
});
