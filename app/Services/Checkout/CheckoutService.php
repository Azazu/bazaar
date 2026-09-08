<?php

namespace App\Services\Checkout;

use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Exceptions\CheckoutBlockedException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Database\Eloquent\Collection;
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
     * Everything the order is made of is read *inside* the transaction, once, from locked rows:
     * the buyer, then the stores, the products and the variants (each set in id order — the
     * same order AccountService and StockManager take their locks), then the coupon. The snapshot lines and the subtotal
     * come from that one collection, so they can't disagree even if a price changes while the
     * buyer is on the checkout page, and every line is re-checked for sellability here,
     * whatever the cart endpoints did or didn't check when it was added.
     *
     * @param  array<string, string>  $shippingAddress
     */
    public function place(User $buyer, array $shippingAddress, string $shippingMethod, ?Coupon $coupon = null): Order
    {
        $cart = $this->cart->lines();

        if ($cart === []) {
            throw new \RuntimeException('Cannot checkout an empty cart.');
        }

        return DB::transaction(function () use ($buyer, $cart, $shippingAddress, $shippingMethod, $coupon) {
            $this->lockBuyer($buyer);
            $variants = $this->lockSellableVariants($cart);

            $subtotal = $variants->sum(fn (ProductVariant $variant) => $variant->price_cents * $cart[$variant->id]);
            $shipping = self::SHIPPING_RATES[$shippingMethod] ?? 0;

            // The coupon is re-validated and reserved on its locked row, here, at order time, and
            // the discount is computed from that locked row — never from what the buyer applied
            // minutes ago, never from a client-side figure — and two checkouts can't share its
            // last use. Cancelling (or expiring) the order releases the reservation.
            $discount = 0;

            if ($coupon !== null) {
                $coupon = $coupon->reserve($subtotal);
                $discount = $coupon->discountFor($subtotal);
            }

            $order = Order::create([
                'buyer_id' => $buyer->id,
                'currency' => 'USD',
                'subtotal_cents' => $subtotal,
                'shipping_cents' => $shipping,
                'discount_cents' => $discount,
                'coupon_id' => $coupon?->id,
                'total_cents' => $subtotal + $shipping - $discount,
                'shipping_address' => $shippingAddress,
                'shipping_method' => $shippingMethod,
            ]);

            // Create snapshot line items from the very rows the subtotal was computed from,
            // grouped by the store they belong to.
            $itemsByStore = [];

            foreach ($variants as $variant) {
                $product = $variant->product ?? throw new LogicException("Variant #{$variant->id} has no product."); // verified above

                $item = $order->items()->create([
                    'product_variant_id' => $variant->id,
                    'sku' => $variant->sku,                        // snapshot
                    'product_title' => $product->title,            // snapshot
                    'variant_name' => $variant->name,              // snapshot
                    'unit_price_cents' => $variant->price_cents,   // snapshot price
                    'qty' => $cart[$variant->id],
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

            return $order;
        });
    }

    /** The buyer must still exist (not soft-deleted); the lock makes a concurrent account closure wait. */
    private function lockBuyer(User $buyer): void
    {
        if (User::query()->whereKey($buyer->getKey())->lockForUpdate()->doesntExist()) {
            throw new CheckoutBlockedException('This account has been closed and can no longer place orders.');
        }
    }

    /**
     * Load every variant in the cart, its product and its store from locked rows, in a stable
     * order — stores, then products, then variants, each set by id — and make sure each line
     * can still be sold: the variant exists, its product is published, its store exists (not
     * archived) and is active. Product and store are taken from the locked rows only, never
     * from a separate relation read, so an unpublish or an archive that commits first is seen
     * and refused, and one that arrives later waits for this order to land.
     *
     * @param  array<int, int>  $cart  [variant_id => qty]
     * @return Collection<int, ProductVariant>
     */
    private function lockSellableVariants(array $cart): Collection
    {
        $variantIds = collect(array_keys($cart))->sort()->values();

        // Which rows to lock. These reads are unlocked, which is fine: product_id and store_id
        // never change, and anything missing at lock time is caught below.
        $productIds = ProductVariant::query()->whereKey($variantIds)->pluck('product_id')->unique()->sort()->values();
        $storeIds = Product::query()->whereKey($productIds)->pluck('store_id')->unique()->sort()->values();

        $stores = Store::query()->whereKey($storeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $products = Product::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variants = ProductVariant::query()->whereKey($variantIds)->orderBy('id')->lockForUpdate()->get();

        if ($variants->count() !== $variantIds->count()) {
            throw new CheckoutBlockedException('An item in your cart is no longer available; please remove it and try again.');
        }

        foreach ($variants as $variant) {
            $product = $products->get($variant->product_id)
                ?? throw new CheckoutBlockedException("{$variant->name} is no longer available; please remove it from your cart.");

            if ($product->status !== ProductStatus::Published) {
                throw new CheckoutBlockedException("{$product->title} is no longer for sale; please remove it from your cart.");
            }

            $store = $stores->get($product->store_id);

            if ($store === null) {
                throw new CheckoutBlockedException("{$product->title} is no longer on the marketplace; please remove it from your cart.");
            }

            if ($store->status !== StoreStatus::Active) {
                throw new CheckoutBlockedException("{$store->name} is not selling at the moment; please remove its items from your cart.");
            }

            $variant->setRelation('product', $product); // the snapshot below reads the locked row, not a fresh one
        }

        return $variants;
    }
}
