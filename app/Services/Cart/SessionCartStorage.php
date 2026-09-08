<?php

namespace App\Services\Cart;

use Closure;
use Illuminate\Contracts\Session\Session;

/**
 * Guest cart: lives in the (Redis-backed) session and disappears with it. A session is
 * written by one request at a time, so plain read-modify-write is atomic enough here.
 */
class SessionCartStorage implements CartStorage
{
    public const KEY = 'cart';

    public const CLAIMS_KEY = 'cart_claims';

    public function __construct(private readonly Session $session) {}

    public function get(): array
    {
        return $this->session->get(self::KEY, []);
    }

    public function put(array $cart): void
    {
        $this->session->put(self::KEY, $cart);
    }

    public function forget(): void
    {
        $this->session->forget([self::KEY, self::CLAIMS_KEY]);
    }

    public function mutate(Closure $mutation): void
    {
        $this->put($mutation($this->get()));
    }

    public function mutateOnce(string $token, Closure $mutation): bool
    {
        $claims = $this->session->get(self::CLAIMS_KEY, []);

        if (in_array($token, $claims, true)) {
            return false;
        }

        $this->put($mutation($this->get()));
        $this->session->put(self::CLAIMS_KEY, [...$claims, $token]);

        return true;
    }
}
