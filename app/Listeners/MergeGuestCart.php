<?php

namespace App\Listeners;

use App\Services\Cart\CartService;
use App\Services\Cart\CartStorageFactory;
use Illuminate\Auth\Events\Login;

/**
 * When a guest logs in, fold their session cart into their account cart so nothing
 * they picked before signing in is lost.
 */
class MergeGuestCart
{
    public function __construct(private readonly CartStorageFactory $storages) {}

    public function handle(Login $event): void
    {
        $guest = $this->storages->forGuest();
        $lines = $guest->get();

        if ($lines === []) {
            return;
        }

        (new CartService($this->storages->forUser($event->user)))->merge($lines);

        $guest->forget();
    }
}
