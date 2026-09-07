<?php

use App\Events\OrderPaid;
use App\Exceptions\OrderNotPayableException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use App\States\Order\Paid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

it('applies the effect once when the provider retries with a new event id', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order);

    // Not every retry reuses the event id — a provider may raise a fresh event for the same
    // charge. The payment's own status is the second guard behind the event ledger.
    app(PaymentService::class)->confirm('evt_first', $payment->transaction_id);
    app(PaymentService::class)->confirm('evt_second', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and(Payment::where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1);

    Event::assertDispatched(OrderPaid::class, 1);
});

it('ignores a webhook for an unknown transaction', function () {
    expect(fn () => app(PaymentService::class)->confirm('evt_unknown', 'no_such_transaction'))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses to start a payment for an order that is not pending', function () {
    $order = Order::factory()->paid()->create();

    expect(fn () => app(PaymentService::class)->start($order))
        ->toThrow(OrderNotPayableException::class);

    expect(Payment::where('order_id', $order->id)->count())->toBe(0);
});
