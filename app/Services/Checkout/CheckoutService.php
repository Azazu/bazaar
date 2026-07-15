<?php

namespace App\Services\Checkout;

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
    public function place(User $buyer, array $shippingAddress, string $shippingMethod): Order
    {
        $lines = $this->cart->items();

        if ($lines->isEmpty()) {
            throw new \RuntimeException('Cannot checkout an empty cart.');
        }

        $subtotal = $this->cart->total();
        $shipping = self::SHIPPING_RATES[$shippingMethod] ?? 0;
        $discount = 0; // coupons arrive in a later block
        $total = $subtotal + $shipping - $discount;

        return DB::transaction(function () use ($buyer, $lines, $shippingAddress, $shippingMethod, $subtotal, $shipping, $discount, $total) {
            $order = Order::create([
                'buyer_id' => $buyer->id,
                'currency' => 'USD',
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $shipping,
                'discount_cents' => $discount,
                'total_cents' => $total,
                'shipping_address' => $shippingAddress,
                'shipping_method' => $shippingMethod,
            ]);

            foreach ($lines as $line) {
                $variant = $line['variant'];

                $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'product_title' => $variant->product->title,   // snapshot
                    'variant_name' => $variant->name,              // snapshot
                    'unit_price_cents' => $variant->price_cents,   // snapshot price
                    'qty' => $line['qty'],
                ]);
            }

            $this->cart->clear();

            return $order;
        });
    }
}
