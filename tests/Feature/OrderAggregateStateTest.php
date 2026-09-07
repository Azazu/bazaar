<?php

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\States\Order\Delivered;
use App\States\Order\Paid;
use App\States\Order\Processing;
use App\States\Order\Shipped;
use App\States\SubOrder\Cancelled;
use App\States\SubOrder\Delivered as SubDelivered;
use App\States\SubOrder\Paid as SubPaid;
use App\States\SubOrder\Processing as SubProcessing;
use App\States\SubOrder\Shipped as SubShipped;

/** @return array{0: Order, 1: SubOrder, 2: SubOrder} a paid order with two paid sub-orders */
function paidOrderWithTwoStores(): array
{
    $order = Order::factory()->paid()->create();
    $a = SubOrder::factory()->create(['order_id' => $order->id, 'status' => 'paid']);
    $b = SubOrder::factory()->create(['order_id' => $order->id, 'status' => 'paid']);

    return [$order, $a, $b];
}

it('moves the parent to processing as soon as one vendor starts', function () {
    [$order, $a] = paidOrderWithTwoStores();

    $a->status->transitionTo(SubProcessing::class);

    expect($order->fresh()->status)->toBeInstanceOf(Processing::class);
});

it('marks the parent shipped only when every vendor has shipped', function () {
    [$order, $a, $b] = paidOrderWithTwoStores();

    $a->status->transitionTo(SubProcessing::class);
    $a->status->transitionTo(SubShipped::class);
    expect($order->fresh()->status)->toBeInstanceOf(Processing::class); // b still packing

    $b->status->transitionTo(SubProcessing::class);
    $b->status->transitionTo(SubShipped::class);
    expect($order->fresh()->status)->toBeInstanceOf(Shipped::class);
});

it('marks the parent delivered when every vendor has delivered, walking through the intermediate states', function () {
    [$order, $a, $b] = paidOrderWithTwoStores();

    foreach ([$a, $b] as $sub) {
        $sub->status->transitionTo(SubProcessing::class);
        $sub->status->transitionTo(SubShipped::class);
        $sub->status->transitionTo(SubDelivered::class);
    }

    expect($order->fresh()->status)->toBeInstanceOf(Delivered::class);
});

it('ignores cancelled sub-orders when deriving the parent state', function () {
    [$order, $a, $b] = paidOrderWithTwoStores();
    $b->status->transitionTo(Cancelled::class);

    $a->status->transitionTo(SubProcessing::class);
    $a->status->transitionTo(SubShipped::class);

    expect($order->fresh()->status)->toBeInstanceOf(Shipped::class);
});

it('does not touch a parent that is not in fulfilment', function () {
    $order = Order::factory()->create(); // pending: payment hasn't happened
    $a = SubOrder::factory()->create(['order_id' => $order->id, 'status' => 'pending']);

    $a->status->transitionTo(SubPaid::class); // MarkSubOrdersPaid does this on payment

    expect($order->fresh()->status)->not->toBeInstanceOf(Paid::class);
});

it('keeps a multi-store order paid until a vendor actually starts fulfilment', function () {
    // Real payment flow: PaymentService → OrderPaid → MarkSubOrdersPaid → per-sub-order sync.
    $variantA = ProductVariant::factory()->create(['stock' => 5]);
    $variantB = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variantA, 1);
    $a = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $variantA->product->store_id]);
    $b = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $variantB->product->store_id]);

    pay($order);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($a->fresh()->status)->toBeInstanceOf(SubPaid::class)
        ->and($b->fresh()->status)->toBeInstanceOf(SubPaid::class)
        ->and($order->fresh()->isCancellable())->toBeTrue(); // nobody has started packing yet

    $a->fresh()->status->transitionTo(SubProcessing::class);

    expect($order->fresh()->status)->toBeInstanceOf(Processing::class)
        ->and($order->fresh()->isCancellable())->toBeFalse();
});
