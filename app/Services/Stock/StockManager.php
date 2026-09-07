<?php

namespace App\Services\Stock;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class StockManager
{
    /**
     * Decrement stock for every line of an order, safely under concurrency.
     *
     * Each variant row is read with lockForUpdate() inside a transaction, so two
     * orders competing for the last unit are serialized by the database — the second
     * one sees the already-decremented stock and is rejected instead of overselling.
     */
    public function decrementForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                $variant = ProductVariant::whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null) {
                    continue;
                }

                if ($variant->stock < $item->qty) {
                    throw new InsufficientStockException($variant, $item->qty);
                }

                $variant->decrement('stock', $item->qty);
            }
        });
    }

    /**
     * Put the units of an order back on the shelf (cancellation or refund).
     *
     * Locked and transactional for the same reason as the decrement: a restore racing
     * a concurrent purchase must not read a stale quantity and clobber it.
     */
    public function restoreForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                $variant = ProductVariant::whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                $variant?->increment('stock', $item->qty);
            }
        });
    }
}
