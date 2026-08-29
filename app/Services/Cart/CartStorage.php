<?php

namespace App\Services\Cart;

/**
 * Where the raw cart lives. The cart itself is a plain [variant_id => qty] map;
 * the storage decides whether it belongs to a guest session or to a user account.
 */
interface CartStorage
{
    /** @return array<int, int> map of [variant_id => qty] */
    public function get(): array;

    /** @param  array<int, int>  $cart */
    public function put(array $cart): void;

    public function forget(): void;
}
