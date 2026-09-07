<?php

namespace App\Services\Checkout;

use App\Enums\StoreStatus;
use App\Exceptions\CheckoutBlockedException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class CheckoutService
{
    /** Flat shipping rates in minor units (cents), keyed by method. */
    public const SHIPPING_RATES = [
        'standard' => 500,
        'express' => 1500,
    ];

    public function __construct(private readonly CartService $cart) {}

    /**
     * Build a pending order (+ snapshot line items) from the current cart, atomically.
     * The cart is left alone: it empties when the order is actually paid (see
     * ReleasePurchasedCartLines), so an abandoned checkout doesn't lose the basket.
     *
     * The buyer and every store in the cart are locked (buyer first, then stores by id — the
     * same order AccountService uses to close accounts and archive stores) and re-checked on
     * that snapshot, so an order can never be created for a closed account or a sub-order for
     * a store that was archived or suspended a moment ago.
     *
     * @param  array<string, string>  $shippingAddress
     */
    public function place(User $buyer, array $shippingAddress, string $shippingMethod, ?Coupon $coupon = null): Order
    {
        $lines = $this->cart->items();

        if ($lines->isEmpty()) {
            throw new \RuntimeException('Cannot checkout an empty cart.');
        }

        $subtotal = $this->cart->total();
        $shipping = self::SHIPPING_RATES[$shippingMethod] ?? 0;

        // Re-validate the coupon at order time — never trust a discount computed on the client.
        $discount = ($coupon && $coupon->isValidFor($subtotal)) ? $coupon->discountFor($subtotal) : 0;
        $couponId = $discount > 0 ? $coupon->id : null;

        $total = $subtotal + $shipping - $discount;

        return DB::transaction(function () use ($buyer, $lines, $shippingAddress, $shippingMethod, $subtotal, $shipping, $discount, $total, $coupon, $couponId) {
            $this->lockAndVerifyParties($buyer, $lines);

            $order = Order::create([
                'buyer_id' => $buyer->id,
                'currency' => 'USD',
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $shipping,
                'discount_cents' => $discount,
                'coupon_id' => $couponId,
                'total_cents' => $total,
                'shipping_address' => $shippingAddress,
                'shipping_method' => $shippingMethod,
            ]);

            // Create snapshot line items, grouped by the store they belong to.
            $itemsByStore = [];

            foreach ($lines as $line) {
                $variant = $line['variant'];
                $product = $variant->product ?? throw new LogicException("Variant #{$variant->id} has no product.");

                $item = $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'product_title' => $product->title,            // snapshot
                    'variant_name' => $variant->name,              // snapshot
                    'unit_price_cents' => $variant->price_cents,   // snapshot price
                    'qty' => $line['qty'],
                ]);

                $itemsByStore[$product->store_id][] = $item;
            }

            // Split into one sub-order per store; re-point each line to its sub-order.
            foreach ($itemsByStore as $storeId => $items) {
                $subOrder = $order->subOrders()->create([
                    'store_id' => $storeId,
                    'status' => 'pending',
                    'subtotal_cents' => collect($items)->sum(fn ($i) => $i->unit_price_cents * $i->qty),
                ]);

                foreach ($items as $item) {
                    $item->update(['sub_order_id' => $subOrder->id]);
                }
            }

            if ($couponId !== null) {
                $coupon->increment('used_count');
            }

            return $order;
        });
    }

    /**
     * Take row locks on the buyer and on each store in the cart, in a stable order, and make
     * sure they can still trade: the buyer is not soft-deleted, every store exists (not
     * archived) and is active. Holding the locks until commit means a concurrent close/archive
     * waits for this order to land and is then refused by its own open-order check.
     *
     * @param  Collection<int, array{variant: ProductVariant, qty: int, line_total_cents: int}>  $lines
     */
    private function lockAndVerifyParties(User $buyer, Collection $lines): void
    {
        if (User::query()->whereKey($buyer->getKey())->lockForUpdate()->doesntExist()) {
            throw new CheckoutBlockedException('This account has been closed and can no longer place orders.');
        }

        $storeIds = $lines
            ->map(fn (array $line) => $line['variant']->product->store_id ?? throw new LogicException("Variant #{$line['variant']->id} has no product."))
            ->unique()
            ->sort()
            ->values();

        $stores = Store::query()->whereKey($storeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($storeIds as $storeId) {
            $store = $stores->get($storeId);

            if ($store === null) {
                throw new CheckoutBlockedException('One of the stores in your cart is no longer on the marketplace.');
            }

            if ($store->status !== StoreStatus::Active) {
                throw new CheckoutBlockedException("{$store->name} is not selling at the moment; please remove its items from your cart.");
            }
        }
    }
}
