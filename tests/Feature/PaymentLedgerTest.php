<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Paid;

/*
 * LO-002: the payment-event ledger is more than an idempotency key — every row says which
 * payment (and order) it concerned, what the event was and what we did about it, and old rows
 * are pruned on a documented retention policy without touching the payments themselves.
 */

it('traces an applied event to its payment, provider, transaction, type and outcome', function () {
    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;

    app(PaymentService::class)->confirm('evt_applied', $payment->transaction_id);

    $event = PaymentEvent::where('event_id', 'evt_applied')->firstOrFail();
    expect($event->payment_id)->toBe($payment->id)
        ->and($event->payment->order_id)->toBe($order->id)
        ->and($event->gateway)->toBe('fake')
        ->and($event->transaction_id)->toBe($payment->transaction_id)
        ->and($event->type)->toBe('payment.succeeded')
        ->and($event->outcome)->toBe(PaymentEvent::OUTCOME_APPLIED)
        ->and($event->processed_at)->not->toBeNull()
        ->and($payment->events()->count())->toBe(1)
        ->and($order->fresh()->status)->toBeInstanceOf(Paid::class);
});

it('records the real outcome when a charge is refunded instead of applied', function () {
    // Sold out between checkout and payment.
    $variant = ProductVariant::factory()->create(['stock' => 1]);
    $order = orderForVariant($variant, 1);
    SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $variant->product->store_id]);
    $payment = app(PaymentService::class)->start($order)->payment;
    $variant->update(['stock' => 0]);

    app(PaymentService::class)->confirm('evt_soldout', $payment->transaction_id);

    expect(PaymentEvent::where('event_id', 'evt_soldout')->value('outcome'))->toBe(PaymentEvent::OUTCOME_REFUNDED_UNFULFILLABLE)
        ->and(PaymentEvent::where('event_id', 'evt_soldout')->value('payment_id'))->toBe($payment->id)
        ->and($order->fresh()->status)->toBeInstanceOf(Cancelled::class);

    // A second intent succeeding for an already-paid order.
    $paidOrder = Order::factory()->create();
    $winner = app(PaymentService::class)->start($paidOrder)->payment;
    app(PaymentService::class)->confirm('evt_win', $winner->transaction_id);
    $stray = $paidOrder->payments()->create(['gateway' => 'fake', 'transaction_id' => 'fake_stray_ledger', 'status' => 'pending', 'amount_cents' => 1, 'currency' => 'USD']);

    app(PaymentService::class)->confirm('evt_stray', 'fake_stray_ledger');

    expect(PaymentEvent::where('event_id', 'evt_stray')->value('outcome'))->toBe(PaymentEvent::OUTCOME_REFUNDED_SURPLUS)
        ->and(PaymentEvent::where('event_id', 'evt_stray')->value('payment_id'))->toBe($stray->id)
        ->and(PaymentEvent::where('event_id', 'evt_win')->value('outcome'))->toBe(PaymentEvent::OUTCOME_APPLIED);
});

it('records a failed attempt reported with an event id, and stays idempotent per event', function () {
    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;

    app(PaymentService::class)->fail($payment->transaction_id, 'evt_failed');
    app(PaymentService::class)->fail($payment->transaction_id, 'evt_failed'); // redelivered

    expect($payment->fresh()->status)->toBe('failed')
        ->and(PaymentEvent::where('event_id', 'evt_failed')->count())->toBe(1)
        ->and(PaymentEvent::where('event_id', 'evt_failed')->value('outcome'))->toBe(PaymentEvent::OUTCOME_ATTEMPT_FAILED)
        ->and(PaymentEvent::where('event_id', 'evt_failed')->value('type'))->toBe('payment.failed');

    // The same succeeded event twice: one ledger row, one effect (the guard the ledger existed for).
    $retry = app(PaymentService::class)->start($order)->payment;
    app(PaymentService::class)->confirm('evt_ok', $retry->transaction_id);
    app(PaymentService::class)->confirm('evt_ok', $retry->transaction_id);
    expect(PaymentEvent::where('event_id', 'evt_ok')->count())->toBe(1)
        ->and(Payment::where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1);

    // A *new* event id for the same, already applied charge: traced, but honestly as ignored.
    app(PaymentService::class)->confirm('evt_ok_again', $retry->transaction_id);
    expect(PaymentEvent::where('event_id', 'evt_ok_again')->value('outcome'))->toBe(PaymentEvent::OUTCOME_IGNORED)
        ->and(PaymentEvent::where('event_id', 'evt_ok')->value('outcome'))->toBe(PaymentEvent::OUTCOME_APPLIED);
});

it('prunes ledger rows past the retention period and keeps the payments themselves', function () {
    config(['bazaar.payment_event_retention_days' => 30]);
    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;
    app(PaymentService::class)->confirm('evt_recent', $payment->transaction_id);
    PaymentEvent::create(['event_id' => 'evt_old', 'payment_id' => $payment->id, 'gateway' => 'fake', 'transaction_id' => $payment->transaction_id, 'type' => 'payment.succeeded', 'outcome' => 'applied', 'processed_at' => now()->subDays(31)]);
    PaymentEvent::create(['event_id' => 'evt_edge', 'payment_id' => $payment->id, 'gateway' => 'fake', 'transaction_id' => $payment->transaction_id, 'type' => 'payment.succeeded', 'outcome' => 'applied', 'processed_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => PaymentEvent::class])->assertSuccessful();

    expect(PaymentEvent::pluck('event_id')->sort()->values()->all())->toBe(['evt_edge', 'evt_recent'])
        ->and(Payment::find($payment->id))->not->toBeNull()          // the financial record is untouched
        ->and($payment->fresh()->status)->toBe('succeeded');

    $this->artisan('schedule:list')->expectsOutputToContain('model:prune');
});

it('does not let a late failure event demote a payment that already succeeded', function () {
    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;

    app(PaymentService::class)->confirm('evt_first_ok', $payment->transaction_id);
    app(PaymentService::class)->fail($payment->transaction_id, 'evt_late_fail'); // delivered out of order

    $late = PaymentEvent::where('event_id', 'evt_late_fail')->firstOrFail();
    expect($payment->fresh()->status)->toBe('succeeded')
        ->and($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($late->type)->toBe('payment.failed')                     // the provider did send a failure…
        ->and($late->outcome)->toBe(PaymentEvent::OUTCOME_IGNORED);    // …and it changed nothing
});
