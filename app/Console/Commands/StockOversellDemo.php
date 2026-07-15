<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Stock\StockManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves StockManager's lockForUpdate under REAL concurrency (MySQL only) by forking
 * N buyers that all race for a variant with limited stock. With the row lock, exactly
 * `stock` buyers succeed and the rest are rejected — never oversold.
 *
 * Dev tool: run against the local MySQL, e.g. `php artisan stock:oversell-demo --buyers=20`.
 */
class StockOversellDemo extends Command
{
    protected $signature = 'stock:oversell-demo {--buyers=10} {--stock=1}';

    protected $description = 'Fork N concurrent buyers racing for limited stock to prove no overselling.';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('This demo mutates data and is disabled in production.');

            return self::FAILURE;
        }

        if (! function_exists('pcntl_fork')) {
            $this->error('pcntl extension is required.');

            return self::FAILURE;
        }

        $buyers = (int) $this->option('buyers');
        $stock = (int) $this->option('stock');

        // Setup: one buyer-owner, a variant with `stock` units, and one pending order per racer.
        $buyer = User::factory()->create();
        $variant = ProductVariant::factory()->create(['stock' => $stock]);

        $orderIds = collect(range(1, $buyers))->map(function () use ($buyer, $variant) {
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

            return $order->id;
        });

        $this->info("Variant #{$variant->id}: stock={$stock}, {$buyers} buyers racing (each wants 1)...");

        $pids = [];
        foreach ($orderIds as $orderId) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error('Could not fork.');

                return self::FAILURE;
            }

            if ($pid === 0) {
                // Child: a fresh DB connection is mandatory after fork.
                DB::purge();
                DB::reconnect();

                try {
                    app(StockManager::class)->decrementForOrder(Order::with('items')->find($orderId));
                    exit(0); // sold
                } catch (InsufficientStockException) {
                    exit(1); // correctly rejected — out of stock
                } catch (\Throwable) {
                    exit(2); // unexpected (deadlock, etc.)
                }
            }

            $pids[] = $pid;
        }

        $sold = $rejected = $errors = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            match (pcntl_wexitstatus($status)) {
                0 => $sold++,
                1 => $rejected++,
                default => $errors++,
            };
        }

        $finalStock = $variant->fresh()->stock;

        $this->table(['metric', 'value'], [
            ['buyers', $buyers],
            ['sold', $sold],
            ['rejected (out of stock)', $rejected],
            ['errors', $errors],
            ['final stock', $finalStock],
        ]);

        $ok = $sold === $stock && $finalStock === 0 && $errors === 0;
        $ok
            ? $this->info("✓ NO OVERSELL: exactly {$stock} sold, stock at 0.")
            : $this->error('✗ Something is off — inspect the numbers above.');

        // Cleanup (buyer cascade removes orders/items; product cascade removes the variant).
        $buyer->delete();
        $variant->product()->delete();

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
