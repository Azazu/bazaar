<?php

namespace App\Services\Cart;

use Closure;

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

    /**
     * Read-modify-write the cart as one atomic step: $mutation receives the current map and
     * returns the new one. Two requests changing the same cart at once (a webhook releasing
     * purchased lines while the buyer adds an item) must not lose each other's change.
     *
     * @param  Closure(array<int, int>): array<int, int>  $mutation
     */
    public function mutate(Closure $mutation): void;

    /**
     * Like mutate(), but at most once per $token: the token is recorded in the same write as
     * the change, under the same lock, so a mutation that never ran (lock timeout, crash)
     * leaves no marker behind and a retry still applies it — exactly once.
     *
     * @param  Closure(array<int, int>): array<int, int>  $mutation
     * @return bool false when $token had already been applied (nothing changed)
     */
    public function mutateOnce(string $token, Closure $mutation): bool;
}
