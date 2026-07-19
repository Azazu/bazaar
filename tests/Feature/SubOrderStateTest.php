<?php

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\SubOrder;
use App\States\SubOrder\Paid;
use App\States\SubOrder\Pending;
use App\States\SubOrder\Shipped;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

it('starts in the pending state', function () {
    expect(SubOrder::factory()->create()->status)->toBeInstanceOf(Pending::class);
});

it('blocks an illegal transition pending -> shipped', function () {
    $sub = SubOrder::factory()->create();

    expect(fn () => $sub->status->transitionTo(Shipped::class))
        ->toThrow(CouldNotPerformTransition::class);
});

it('marks its sub-orders paid when the order is paid', function () {
    $order = Order::factory()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id]); // pending

    OrderPaid::dispatch($order);

    expect($sub->fresh()->status)->toBeInstanceOf(Paid::class);
});
