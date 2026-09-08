<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\ProductVariant;
use App\Services\Payment\PaymentService;
use App\States\Order\Paid;

beforeEach(fn () => requiresDatabaseConcurrency());

/*
 * LO-002 follow-up: "payment_intent.succeeded" and "payment_intent.payment_failed" for the same
 * intent can land at the same instant (or in either order). A failure must never demote a
 * charge that went through: fail() reads the payment under its row lock and only moves
 * pending → failed, confirm() holds the same lock while it marks it succeeded.
 */

it('never lets a racing failure event demote a succeeded payment', function () {
    foreach (range(1, 3) as $round) {
        $variant = ProductVariant::factory()->create(['stock' => 10]);
        $order = orderForVariant($variant, 1);
        $transactionId = app(PaymentService::class)->start($order)->payment->transaction_id;

        $result = raceInParallel(4, function (int $i) use ($transactionId, $round) {
            $i % 2 === 1
                ? app(PaymentService::class)->confirm("evt_ok_{$round}_{$i}", $transactionId)
                : app(PaymentService::class)->fail($transactionId, "evt_fail_{$round}_{$i}");

            return true;
        });

        $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();

        expect($result['failed'])->toBe(0, "round {$round}")
            ->and($payment->status)->toBe('succeeded', "round {$round}: the charge went through, whatever else arrived")
            ->and($order->fresh()->status)->toBeInstanceOf(Paid::class)
            ->and($order->fresh()->payment_id)->toBe($payment->id)
            ->and($variant->fresh()->stock)->toBe(9)                                     // decremented once
            ->and(PaymentEvent::where('transaction_id', $transactionId)->count())->toBe(4)                                        // every event traced
            ->and(PaymentEvent::where('transaction_id', $transactionId)->where('outcome', PaymentEvent::OUTCOME_APPLIED)->count())->toBe(1)
            // Failures are attempt_failed if they beat the success (pending → failed), ignored if they came after; the
            // second success is always ignored. Which of those happens depends on webhook ordering, so accept both.
            ->and(PaymentEvent::where('transaction_id', $transactionId)->where('type', 'payment.failed')->pluck('outcome')->all())
            ->each->toBeIn([PaymentEvent::OUTCOME_ATTEMPT_FAILED, PaymentEvent::OUTCOME_IGNORED])
            ->and(PaymentEvent::where('transaction_id', $transactionId)->where('type', 'payment.succeeded')->where('outcome', PaymentEvent::OUTCOME_IGNORED)->count())->toBe(1);
    }
});
