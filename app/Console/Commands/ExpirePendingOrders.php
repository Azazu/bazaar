<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Console\Command;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * Abandoned checkouts: a pending order that was never paid keeps the coupon use it reserved
 * (and clutters the buyer's order list) forever. After bazaar.pending_order_ttl_hours it is
 * cancelled through OrderService — the same path as a buyer's cancellation, so the coupon is
 * released and the buyer is told. Nothing else moves: an unpaid order took no stock and no
 * money. An order whose payment attempt was touched inside the window is skipped: the buyer
 * may be on the payment page right now, and a late webhook for a cancelled order would only
 * end in a refund. Scheduled hourly.
 */
class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending {--ttl= : Hours a pending order may stay unpaid (default: bazaar.pending_order_ttl_hours)}';

    protected $description = 'Cancel pending orders that were never paid, releasing their coupon reservations';

    public function handle(OrderService $orders): int
    {
        $ttl = (int) ($this->option('ttl') ?? config('bazaar.pending_order_ttl_hours'));
        $cutoff = now()->subHours($ttl);

        $stale = Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('payments', fn ($payment) => $payment->where('status', 'pending')->where('updated_at', '>', $cutoff))
            ->orderBy('id')
            ->get();

        $expired = 0;

        foreach ($stale as $order) {
            try {
                $orders->cancel($order);
                $expired++;
            } catch (CouldNotPerformTransition) {
                // Paid (or cancelled) between our read and the locked cancel: leave it.
            }
        }

        $this->info("Expired {$expired} pending order(s) older than {$ttl}h.");

        return self::SUCCESS;
    }
}
