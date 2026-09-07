<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The provider half of a refund. The local half (order reversed, payment marked
 * refund_pending, stock and payouts settled) is committed first; this job then returns the
 * money and marks the payment refunded. It is safe to run any number of times: it skips
 * payments that are no longer refund_pending, and the gateway call is idempotent per
 * payment. Failures (timeouts, provider errors) are retried with growing delays, and
 * anything still refund_pending after that is picked up by `payments:retry-refunds`.
 */
class RefundPayment implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Payment $payment) {}

    /** @return array<int, int> seconds before each retry */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(PaymentService $payments): void
    {
        $payments->executeRefund($this->payment);
    }
}
