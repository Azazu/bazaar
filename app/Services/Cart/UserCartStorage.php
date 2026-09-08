<?php

namespace App\Services\Cart;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Account cart: keyed by user id in the cache store (Redis), so it follows the user
 * across devices and sessions — and is the only cart a stateless API client can have.
 *
 * One cache value holds both the lines and the tokens of once-only mutations already
 * applied (`['lines' => [...], 'claims' => [...]]`), and every change is made under a lock:
 * a change and its idempotency marker land in a single write, or not at all.
 */
class UserCartStorage implements CartStorage
{
    /** How long a writer may hold the cart, and how long another one waits for it (seconds). */
    private const int LOCK_TTL = 5;

    private const int LOCK_WAIT = 3;

    /** Tokens remembered per cart; older ones fall off (an order is released once, shortly after payment). */
    private const int CLAIMS_KEPT = 50;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $userId,
        private readonly int $ttlDays,
    ) {}

    public function get(): array
    {
        return $this->payload()['lines'];
    }

    public function put(array $cart): void
    {
        $this->write(['lines' => $cart, 'claims' => $this->payload()['claims']]);
    }

    public function forget(): void
    {
        $this->cache->forget($this->key());
    }

    public function mutate(Closure $mutation): void
    {
        $this->locked(function () use ($mutation) {
            $payload = $this->payload();
            $payload['lines'] = $mutation($payload['lines']);
            $this->write($payload);
        });
    }

    public function mutateOnce(string $token, Closure $mutation): bool
    {
        return $this->locked(function () use ($token, $mutation): bool {
            $payload = $this->payload();

            if (in_array($token, $payload['claims'], true)) {
                return false;
            }

            $payload['lines'] = $mutation($payload['lines']);
            $payload['claims'] = array_slice([...$payload['claims'], $token], -self::CLAIMS_KEPT);
            $this->write($payload); // change + marker, one write

            return true;
        });
    }

    /**
     * Run $work under the cart's atomic cache lock (Redis SET NX), so concurrent writers to the
     * same account cart line up instead of overwriting each other's snapshot.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            return $store->lock($this->key().':lock', self::LOCK_TTL)->block(self::LOCK_WAIT, $work);
        }

        return $work(); // a store without locks (none we use) degrades to plain read-modify-write
    }

    /**
     * The stored value, normalised. A plain [variant_id => qty] map from before claims were
     * stored alongside is read as lines with no claims.
     *
     * @return array{lines: array<int, int>, claims: list<string>}
     */
    private function payload(): array
    {
        $stored = $this->cache->get($this->key(), []);

        if (! is_array($stored)) {
            return ['lines' => [], 'claims' => []];
        }

        if (array_key_exists('lines', $stored) && is_array($stored['lines'])) {
            /** @var array<int, int> $lines */
            $lines = $stored['lines'];
            /** @var list<string> $claims */
            $claims = array_values(array_filter(is_array($stored['claims'] ?? null) ? $stored['claims'] : [], 'is_string'));

            return ['lines' => $lines, 'claims' => $claims];
        }

        /** @var array<int, int> $stored */
        return ['lines' => $stored, 'claims' => []];
    }

    /** @param  array{lines: array<int, int>, claims: list<string>}  $payload */
    private function write(array $payload): void
    {
        $this->cache->put($this->key(), $payload, now()->addDays($this->ttlDays));
    }

    private function key(): string
    {
        return "cart:user:{$this->userId}";
    }
}
