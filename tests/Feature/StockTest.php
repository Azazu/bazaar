<?php

use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariant;
use App\States\Order\Paid;
use App\States\Order\Pending;

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
