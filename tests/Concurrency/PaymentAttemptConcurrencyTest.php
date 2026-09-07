<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Services\Payment\PaymentService;
use App\States\Order\Paid;

beforeEach(fn () => requiresDatabaseConcurrency());

/*
 * CR-001: one order must never end up with two payments that were both charged and kept.
 * Two guards, each raced here against MySQL: start() serializes attempts under the order's
 * row lock, and confirm() lets exactly one payment win the pending → paid transition,
 * refunding any other charge for the same order.
 */

it('creates a single payment attempt when the buyer starts paying several times at once', function () {
    $variant = ProductVariant::factory()->create(['stock' => 10]);
    $order = orderForVariant($variant, 1);

    $result = raceInParallel(8, function () use ($order) {
        app(PaymentService::class)->start($order);

        return true;
    });

    expect($result)->toBe(['ok' => 8, 'rejected' => 0, 'failed' => 0])
        ->and(Payment::where('order_id', $order->id)->count())->toBe(1); // everyone resumed the same attempt
});

it('keeps exactly one charge when several intents of one order succeed simultaneously', function () {
    $variant = ProductVariant::factory()->create(['stock' => 10]);
    $order = orderForVariant($variant, 2);
    $subOrder = SubOrder::factory()->create([
        'order_id' => $order->id,
        'store_id' => $variant->product->store_id,
        'subtotal_cents' => $order->subtotal_cents,
    ]);

    // Several live intents for one order (provider-side retries, or rows from before start()
    // was serialized), each confirmed by its own webhook at the same instant.
    $transactions = collect(range(1, 6))->map(fn (int $i) => $order->payments()->create([
        'gateway' => 'fake', 'transaction_id' => "fake_race_{$i}", 'status' => 'pending',
        'amount_cents' => $order->total_cents, 'currency' => $order->currency,
    ])->transaction_id)->all();

    $result = raceInParallel(count($transactions), function (int $i) use ($transactions) {
        app(PaymentService::class)->confirm("evt_race_{$i}", $transactions[$i - 1]); // workers are numbered from 1

        return true;
    });

    $payments = Payment::where('order_id', $order->id)->get();

    expect($result['failed'])->toBe(0)
        ->and($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($payments->where('status', 'succeeded')->count())->toBe(1)
        ->and($payments->where('status', 'refunded')->count())->toBe(5)
        ->and($order->fresh()->payment_id)->toBe($payments->firstWhere('status', 'succeeded')->id)
        ->and($variant->fresh()->stock)->toBe(8)                    // decremented exactly once
        ->and($subOrder->fresh()->payout()->count())->toBe(1);      // one payout, not six
});
