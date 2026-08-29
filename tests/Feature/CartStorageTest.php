<?php

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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

    expect(Cache::get("cart:user:{$user->id}"))->toBe([$variant->id => 2])
        ->and(session('cart'))->toBeNull();
});

it('merges the guest cart into the account cart on login', function () {
    $user = User::factory()->create();
    [$a, $b] = ProductVariant::factory()->count(2)->create();
    Cache::put("cart:user:{$user->id}", [$a->id => 1]); // what they already had in the account

    app(CartService::class)->add($a->id, 2); // picked as a guest
    app(CartService::class)->add($b->id, 1);

    Auth::login($user); // fires the Login event

    expect(Cache::get("cart:user:{$user->id}"))->toBe([$a->id => 3, $b->id => 1])
        ->and(session('cart'))->toBeNull()
        ->and(app(CartService::class)->count())->toBe(4);
});
