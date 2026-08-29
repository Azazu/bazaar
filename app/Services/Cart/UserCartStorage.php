<?php

namespace App\Services\Cart;

use Illuminate\Contracts\Cache\Repository;

/**
 * Account cart: keyed by user id in the cache store (Redis), so it follows the user
 * across devices and sessions — and is the only cart a stateless API client can have.
 */
class UserCartStorage implements CartStorage
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $userId,
        private readonly int $ttlDays,
    ) {}

    public function get(): array
    {
        return $this->cache->get($this->key(), []);
    }

    public function put(array $cart): void
    {
        $this->cache->put($this->key(), $cart, now()->addDays($this->ttlDays));
    }

    public function forget(): void
    {
        $this->cache->forget($this->key());
    }

    private function key(): string
    {
        return "cart:user:{$this->userId}";
    }
}
