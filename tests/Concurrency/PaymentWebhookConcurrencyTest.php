<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\States\Order\Paid;

beforeEach(fn () => requiresDatabaseConcurrency());

it('applies a webhook once when the provider delivers the same event simultaneously', function () {
    // A provider that retries aggressively can have several deliveries of one event in flight at
    // the same moment. The unique event ledger plus firstOrCreate (which re-reads the row on a
    // unique violation) must collapse them into a single effect.
    $deliveries = 8;
    $variant = ProductVariant::factory()->create(['stock' => 10]);
    $store = $variant->product->store;

    $order = Order::factory()->create([
        'buyer_id' => User::factory()->create()->id,
        'subtotal_cents' => $variant->price_cents * 2,
        'shipping_cents' => 0,
        'total_cents' => $variant->price_cents * 2,
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_title' => $variant->product->title,
        'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents,
        'qty' => 2,
    ]);
    $subOrder = SubOrder::factory()->create([
        'order_id' => $order->id,
        'store_id' => $store->id,
        'subtotal_cents' => $variant->price_cents * 2,
    ]);

    $transactionId = app(PaymentService::class)->start($order)->payment->transaction_id;

    $result = raceInParallel($deliveries, function () use ($transactionId) {
        app(PaymentService::class)->confirm('evt_duplicate', $transactionId);

        return true;
    });

    expect($result['failed'])->toBe(0)
        ->and(PaymentEvent::where('event_id', 'evt_duplicate')->count())->toBe(1)
        ->and($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and(Payment::where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1)
        ->and($variant->fresh()->stock)->toBe(8)                    // decremented exactly once
        ->and($subOrder->fresh()->payout)->not->toBeNull()
        ->and($store->payouts()->count())->toBe(1);                 // one payout, not eight
});
