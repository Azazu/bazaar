<?php

use App\Models\Order;
use App\States\Order\Paid;
use App\States\Order\Pending;
use App\States\Order\Shipped;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

it('starts in the pending state', function () {
    $order = Order::factory()->create();

    expect($order->status)->toBeInstanceOf(Pending::class);
});

it('allows a legal transition pending -> paid', function () {
    $order = Order::factory()->create();

    $order->status->transitionTo(Paid::class);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class);
});

it('blocks an illegal transition pending -> shipped', function () {
    $order = Order::factory()->create();

    expect(fn () => $order->status->transitionTo(Shipped::class))
        ->toThrow(CouldNotPerformTransition::class);
});
