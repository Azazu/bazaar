<?php

namespace App\Console\Commands;

use App\Jobs\RefundPayment;
use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Reconciliation for refunds that never finished: an order was reversed and its payment
 * marked refund_pending, but the RefundPayment job exhausted its retries (or the queue lost
 * it). Re-queue every payment still waiting longer than the grace period. Scheduled hourly.
 */
class RetryPendingRefunds extends Command
{
    protected $signature = 'payments:retry-refunds {--older-than=15 : Minutes a refund may stay pending before it is re-queued}';

    protected $description = 'Re-queue provider refunds that are still pending after the grace period';

    public function handle(): int
    {
        $stale = Payment::query()
            ->where('status', 'refund_pending')
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->get();

        foreach ($stale as $payment) {
            RefundPayment::dispatch($payment);
        }

        $this->info("Re-queued {$stale->count()} pending refund(s).");

        return self::SUCCESS;
    }
}
