<?php

use App\Models\Product;
use App\Models\Store;
use App\Models\User;

it('belongs to an owner and has products', function () {
    $store = Store::factory()->create();
    Product::factory()->count(2)->create(['store_id' => $store->id]);

    expect($store->owner)->toBeInstanceOf(User::class)
        ->and($store->products)->toHaveCount(2);
});

it('scopes active stores only', function () {
    Store::factory()->create();            // active by default
    Store::factory()->pending()->create(); // pending moderation

    expect(Store::active()->count())->toBe(1);
});
