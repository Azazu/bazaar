<?php

namespace App\Services\Cart;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;

class CartService
{
    private const SESSION_KEY = 'cart';

    /** Add a variant to the cart (or bump its quantity). */
    public function add(int $variantId, int $qty = 1): void
    {
        $cart = $this->raw();
        $cart[$variantId] = ($cart[$variantId] ?? 0) + $qty;
        $this->save($cart);
    }

    /** Set an exact quantity (removes the line if qty <= 0). */
    public function update(int $variantId, int $qty): void
    {
        $cart = $this->raw();

        if ($qty <= 0) {
            unset($cart[$variantId]);
        } else {
            $cart[$variantId] = $qty;
        }

        $this->save($cart);
    }

    public function remove(int $variantId): void
    {
        $cart = $this->raw();
        unset($cart[$variantId]);
        $this->save($cart);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /** Cart lines: each variant (with product) + quantity + line total. */
    public function items(): Collection
    {
        $cart = $this->raw();

        if (empty($cart)) {
            return collect();
        }

        // One query for all variants in the cart (no N+1).
        return ProductVariant::with('product')
            ->whereIn('id', array_keys($cart))
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'variant' => $variant,
                'qty' => $cart[$variant->id],
                'line_total_cents' => $variant->price_cents * $cart[$variant->id],
            ]);
    }

    /** Grand total in minor units (cents). */
    public function total(): int
    {
        return $this->items()->sum('line_total_cents');
    }

    /** Total quantity across all lines (for the header badge). */
    public function count(): int
    {
        return array_sum($this->raw());
    }

    /** @return array<int,int> map of [variant_id => qty] */
    private function raw(): array
    {
        return session()->get(self::SESSION_KEY, []);
    }

    private function save(array $cart): void
    {
        session()->put(self::SESSION_KEY, $cart);
    }
}
