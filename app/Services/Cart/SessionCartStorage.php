<?php

namespace App\Services\Cart;

use Illuminate\Contracts\Session\Session;

/** Guest cart: lives in the (Redis-backed) session and disappears with it. */
class SessionCartStorage implements CartStorage
{
    public const KEY = 'cart';

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
        $this->session->forget(self::KEY);
    }
}
