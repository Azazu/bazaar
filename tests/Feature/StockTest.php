<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Payment\PaymentService;
use App\States\Order\Paid;
use App\States\Order\Pending;

/** Build a pending order with a single line for the given variant. */
function orderForVariant(ProductVariant $variant, int $qty): Order
{
    $order = Order::factory()->create([
        'subtotal_cents' => $variant->price_cents * $qty,
        'shipping_cents' => 0,
        'total_cents' => $variant->price_cents * $qty,
    ]);

    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_title' => $variant->product->title,
        'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents,
        'qty' => $qty,
    ]);

    return $order->load('items');
}

/** Pay an order through the (sandbox) payment service. */
function pay(Order $order): void
{
    $payment = app(PaymentService::class)->start($order);
    app(PaymentService::class)->confirm('evt_'.uniqid(), $payment->transaction_id);
}

it('decrements variant stock when an order is paid', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 2);

    pay($order);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($variant->fresh()->stock)->toBe(3);
});

it('prevents overselling the last unit', function () {
    $variant = ProductVariant::factory()->create(['stock' => 1]);
    $orderA = orderForVariant($variant, 1);
    $orderB = orderForVariant($variant, 1);

    pay($orderA);
    expect($variant->fresh()->stock)->toBe(0);

    // Paying B would oversell — it must fail and roll back.
    expect(fn () => pay($orderB))->toThrow(InsufficientStockException::class);

    expect($orderB->fresh()->status)->toBeInstanceOf(Pending::class) // rolled back to pending
        ->and($variant->fresh()->stock)->toBe(0);                    // never goes negative
});
