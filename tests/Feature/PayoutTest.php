<?php

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\SubOrder;

it('creates a payout per sub-order with the platform commission deducted', function () {
    config(['bazaar.commission_rate' => 0.10]);

    $order = Order::factory()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id, 'subtotal_cents' => 10000]);

    OrderPaid::dispatch($order);

    $payout = $sub->fresh()->payout;

    expect($payout)->not->toBeNull()
        ->and($payout->commission_cents)->toBe(1000) // 10% of 10000
        ->and($payout->amount_cents)->toBe(9000)     // vendor keeps the rest
        ->and($payout->store_id)->toBe($sub->store_id);
});

it('splits money with no lost cents when the rate does not divide evenly', function () {
    config(['bazaar.commission_rate' => 0.10]);

    $order = Order::factory()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id, 'subtotal_cents' => 9999]);

    OrderPaid::dispatch($order);
    $payout = $sub->fresh()->payout;

    // commission + vendor amount must reconcile exactly to the sub-order subtotal
    expect($payout->commission_cents + $payout->amount_cents)->toBe(9999);
});
