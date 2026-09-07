<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Services\Order\OrderService;
use App\Services\Order\SubOrderService;
use App\States\Order\Cancelled;
use App\States\Order\Processing;
use App\States\Order\Refunded;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use App\States\SubOrder\Processing as SubOrderProcessing;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

beforeEach(fn () => requiresDatabaseConcurrency());

/*
 * CR-003: cancel/refund claim the order under row locks, so however many callers race, the
 * reversal — restock, voided payouts, refund request — happens exactly once, and a vendor
 * advancing a sub-order at the same moment gets one of two consistent outcomes.
 */

/** @return array{0: Order, 1: ProductVariant, 2: SubOrder} a paid order for 2 of 5 units, stock now 3 */
function paidOrderToReverse(): array
{
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 2);
    $subOrder = SubOrder::factory()->create([
        'order_id' => $order->id,
        'store_id' => $variant->product->store_id,
        'subtotal_cents' => $order->subtotal_cents,
    ]);
    pay($order);

    return [$order->refresh(), $variant, $subOrder];
}

it('restores stock once when several cancellations of one order race', function () {
    [$order, $variant, $subOrder] = paidOrderToReverse();

    $result = raceInParallel(6, function () use ($order) {
        try {
            app(OrderService::class)->cancel(Order::findOrFail($order->id));

            return true;
        } catch (CouldNotPerformTransition) {
            return false; // the state machine refused: someone else got there first
        }
    });

    expect($result)->toBe(['ok' => 1, 'rejected' => 5, 'failed' => 0])
        ->and($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($subOrder->fresh()->status)->toBeInstanceOf(SubOrderCancelled::class)
        ->and($variant->fresh()->stock)->toBe(5)                                         // 3 + 2, once
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled')
        ->and(Payment::where('order_id', $order->id)->whereIn('status', ['refund_pending', 'refunded'])->count())->toBe(1);
});

it('restores stock once when several refunds of one order race', function () {
    [$order, $variant, $subOrder] = paidOrderToReverse();

    $result = raceInParallel(6, function () use ($order) {
        try {
            app(OrderService::class)->refund(Order::findOrFail($order->id));

            return true;
        } catch (CouldNotPerformTransition) {
            return false;
        }
    });

    expect($result)->toBe(['ok' => 1, 'rejected' => 5, 'failed' => 0])
        ->and($order->fresh()->status)->toBeInstanceOf(Refunded::class)
        ->and($variant->fresh()->stock)->toBe(5)
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled');
});

it('lets either the cancellation or the vendor advance win, never both', function () {
    [$order, $variant, $subOrder] = paidOrderToReverse();

    $result = raceInParallel(2, function (int $i) use ($order, $subOrder) {
        try {
            $i === 1
                ? app(OrderService::class)->cancel(Order::findOrFail($order->id))
                : app(SubOrderService::class)->advance(SubOrder::findOrFail($subOrder->id), SubOrderProcessing::class);

            return true;
        } catch (CouldNotPerformTransition) {
            return false;
        }
    });

    $order->refresh();
    $subOrder->refresh();

    expect($result['failed'])->toBe(0)->and($result['ok'])->toBe(1)->and($result['rejected'])->toBe(1);

    if ($order->status instanceof Cancelled) {
        expect($subOrder->status)->toBeInstanceOf(SubOrderCancelled::class)
            ->and($variant->fresh()->stock)->toBe(5);
    } else {
        expect($order->status)->toBeInstanceOf(Processing::class)
            ->and($subOrder->status)->toBeInstanceOf(SubOrderProcessing::class)
            ->and($variant->fresh()->stock)->toBe(3);
    }
});
