<?php

namespace App\Services\Cart;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Session\Session;

/** Builds the right CartStorage for a viewer; the container binding and the login merge both use it. */
class CartStorageFactory
{
    public function __construct(
        private readonly Session $session,
        private readonly Repository $cache,
    ) {}

    public function forGuest(): CartStorage
    {
        return new SessionCartStorage($this->session);
    }

    public function forUser(Authenticatable $user): CartStorage
    {
        return new UserCartStorage(
            $this->cache,
            (int) $user->getAuthIdentifier(),
            (int) config('bazaar.cart_ttl_days'),
        );
    }

    /** Account cart when logged in (web session or API token), session cart otherwise. */
    public function forViewer(?Authenticatable $user): CartStorage
    {
        return $user ? $this->forUser($user) : $this->forGuest();
    }
}
