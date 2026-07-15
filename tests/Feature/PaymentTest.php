<?php

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use App\States\Order\Paid;
use Illuminate\Support\Facades\Event;

it('marks the order paid on a successful payment', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create(); // pending
    $payment = app(PaymentService::class)->start($order);

    app(PaymentService::class)->confirm('evt_1', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($payment->fresh()->status)->toBe('succeeded');

    Event::assertDispatched(OrderPaid::class, 1);
});

it('is idempotent when the same payment event arrives twice', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order);

    // A provider may deliver the same webhook more than once.
    app(PaymentService::class)->confirm('evt_dup', $payment->transaction_id);
    app(PaymentService::class)->confirm('evt_dup', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and(Payment::where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1);

    Event::assertDispatched(OrderPaid::class, 1); // fired exactly once
});
