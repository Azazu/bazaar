<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Paid;

beforeEach(fn () => requiresDatabaseConcurrency());

/**
 * Give each racer its own pending order for one unit of the variant, plus a started payment.
 *
 * @return array<int, string> transaction ids, one per racer
 */
function racingPayments(ProductVariant $variant, int $racers): array
{
    $buyer = User::factory()->create();

    return collect(range(1, $racers))->map(function () use ($buyer, $variant) {
        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'subtotal_cents' => $variant->price_cents,
            'shipping_cents' => 0,
            'total_cents' => $variant->price_cents,
        ]);

        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_title' => $variant->product->title,
            'variant_name' => $variant->name,
            'unit_price_cents' => $variant->price_cents,
            'qty' => 1,
        ]);

        return app(PaymentService::class)->start($order)->payment->transaction_id;
    })->all();
}

it('never oversells when many buyers pay for the last unit at once', function () {
    $racers = 10;
    $variant = ProductVariant::factory()->create(['stock' => 1]);
    $transactions = racingPayments($variant, $racers);

    // A worker "wins" when its payment ends up succeeded; a loser's payment is refunded and its order
    // cancelled by confirm() itself (someone else took the unit), never an exception.
    $result = raceInParallel($racers, function (int $i) use ($transactions) {
        app(PaymentService::class)->confirm('evt_race_'.$i, $transactions[$i - 1]);

        return Payment::where('transaction_id', $transactions[$i - 1])->value('status') === 'succeeded';
    });

    expect($result)->toBe(['ok' => 1, 'rejected' => $racers - 1, 'failed' => 0])
        ->and($variant->fresh()->stock)->toBe(0)                                     // never negative
        ->and(Order::where('status', Paid::$name)->count())->toBe(1)                 // exactly one sale
        ->and(Order::where('status', Cancelled::$name)->count())->toBe($racers - 1)  // the rest cancelled…
        ->and(Payment::where('status', 'refunded')->count())->toBe($racers - 1);     // …and refunded
});

it('sells exactly the available units when buyers outnumber stock', function () {
    $racers = 12;
    $stock = 4;
    $variant = ProductVariant::factory()->create(['stock' => $stock]);
    $transactions = racingPayments($variant, $racers);

    $result = raceInParallel($racers, function (int $i) use ($transactions) {
        app(PaymentService::class)->confirm('evt_batch_'.$i, $transactions[$i - 1]);

        return Payment::where('transaction_id', $transactions[$i - 1])->value('status') === 'succeeded';
    });

    expect($result)->toBe(['ok' => $stock, 'rejected' => $racers - $stock, 'failed' => 0])
        ->and($variant->fresh()->stock)->toBe(0)
        ->and(Order::where('status', Paid::$name)->count())->toBe($stock)
        ->and(Payment::where('status', 'refunded')->count())->toBe($racers - $stock);
});
