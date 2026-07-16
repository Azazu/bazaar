<?php

namespace App\Services\Checkout;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    /** Flat shipping rates in minor units (cents), keyed by method. */
    private const SHIPPING_RATES = [
        'standard' => 500,
        'express' => 1500,
    ];

    public function __construct(private readonly CartService $cart) {}

    /**
     * Build a pending order (+ snapshot line items) from the current cart,
     * atomically, then clear the cart. Returns the created order.
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

                $item = $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'product_title' => $variant->product->title,   // snapshot
                    'variant_name' => $variant->name,              // snapshot
                    'unit_price_cents' => $variant->price_cents,   // snapshot price
                    'qty' => $line['qty'],
                ]);

                $itemsByStore[$variant->product->store_id][] = $item;
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

            $this->cart->clear();

            return $order;
        });
    }
}
