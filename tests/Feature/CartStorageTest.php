<?php

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\CartStorageFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/** @return array<int, int> the user's account cart as the storage reads it */
function accountCart(User $user): array
{
    return app(CartStorageFactory::class)->forUser($user)->get();
}

it('keeps a guest cart in the session', function () {
    $variant = ProductVariant::factory()->create();

    app(CartService::class)->add($variant->id, 2);

    expect(session('cart'))->toBe([$variant->id => 2]);
});

it('keeps an authenticated user\'s cart in the account store', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();

    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 2);

    expect(accountCart($user))->toBe([$variant->id => 2])
        ->and(Cache::has("cart:user:{$user->id}"))->toBeTrue()
        ->and(session('cart'))->toBeNull();
});

it('merges the guest cart into the account cart on login', function () {
    $user = User::factory()->create();
    [$a, $b] = ProductVariant::factory()->count(2)->create();
    app(CartStorageFactory::class)->forUser($user)->put([$a->id => 1]); // what they already had in the account

    app(CartService::class)->add($a->id, 2); // picked as a guest
    app(CartService::class)->add($b->id, 1);

    Auth::login($user); // fires the Login event

    expect(accountCart($user))->toBe([$a->id => 3, $b->id => 1])
        ->and(session('cart'))->toBeNull()
        ->and(app(CartService::class)->count())->toBe(4);
});

it('changes an account cart only under its lock, so concurrent writers cannot overwrite each other', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);

    // Somebody else (a webhook releasing purchased lines) is holding the cart right now.
    $held = Cache::lock("cart:user:{$user->id}:lock", 10);
    expect($held->get())->toBeTrue();

    // Our write waits for the lock rather than reading a snapshot and clobbering theirs.
    expect(fn () => app(CartService::class)->add($variant->id, 1))->toThrow(LockTimeoutException::class);
    expect(accountCart($user))->toBe([]);

    $held->release();
    app(CartService::class)->add($variant->id, 1);
    expect(accountCart($user))->toBe([$variant->id => 1]);
});

it('leaves no idempotency marker behind when the release could not run, so the retry releases exactly once', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    $this->actingAs($user);
    app(CartService::class)->add($variant->id, 3);

    $held = Cache::lock("cart:user:{$user->id}:lock", 10);
    expect($held->get())->toBeTrue();

    // The webhook's release times out on the lock: nothing changed, and — crucially — nothing marked.
    expect(fn () => app(CartService::class)->releaseOnce('order-9', [$variant->id => 2]))->toThrow(LockTimeoutException::class);
    expect(accountCart($user))->toBe([$variant->id => 3]);

    $held->release();

    // The retry is the first successful run: it releases…
    expect(app(CartService::class)->releaseOnce('order-9', [$variant->id => 2]))->toBeTrue()
        ->and(accountCart($user))->toBe([$variant->id => 1]);

    // …and a further replay is a no-op.
    expect(app(CartService::class)->releaseOnce('order-9', [$variant->id => 2]))->toBeFalse()
        ->and(accountCart($user))->toBe([$variant->id => 1]);
});

it('reads a cart stored before claims lived alongside the lines', function () {
    $user = User::factory()->create();
    $variant = ProductVariant::factory()->create();
    Cache::put("cart:user:{$user->id}", [$variant->id => 2]); // legacy shape: the bare map

    $this->actingAs($user);
    expect(app(CartService::class)->lines())->toBe([$variant->id => 2]);

    app(CartService::class)->add($variant->id, 1);
    expect(accountCart($user))->toBe([$variant->id => 3])
        ->and(app(CartService::class)->releaseOnce('order-1', [$variant->id => 3]))->toBeTrue()
        ->and(accountCart($user))->toBe([]);
});

it('releases purchased quantities, never more, and only once per order', function () {
    $user = User::factory()->create();
    [$a, $b] = ProductVariant::factory()->count(2)->create();
    $this->actingAs($user);
    app(CartService::class)->add($a->id, 3);
    app(CartService::class)->add($b->id, 1);

    expect(app(CartService::class)->releaseOnce('order-1', [$a->id => 2]))->toBeTrue()
        ->and(app(CartService::class)->lines())->toBe([$a->id => 1, $b->id => 1]);

    expect(app(CartService::class)->releaseOnce('order-1', [$a->id => 2]))->toBeFalse()   // replay: no-op
        ->and(app(CartService::class)->lines())->toBe([$a->id => 1, $b->id => 1]);

    expect(app(CartService::class)->releaseOnce('order-2', [$a->id => 5, $b->id => 1]))->toBeTrue()
        ->and(app(CartService::class)->lines())->toBe([]);                                 // never below zero
});
