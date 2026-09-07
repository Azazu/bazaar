<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** A published product whose store is not active — it must be offline everywhere. */
function productOfSuspendedStore(): Product
{
    $store = Store::factory()->create(['status' => 'suspended']);

    return Product::factory()->for($store)->create();
}

it('hides products of suspended or pending stores from the catalog and the API', function () {
    $hidden = productOfSuspendedStore();
    $shown = Product::factory()->create(); // factory stores are active

    $this->get(route('catalog.index'))->assertSee($shown->title)->assertDontSee($hidden->title);
    $this->get(route('products.show', $hidden))->assertNotFound();

    $this->getJson('/api/v1/products')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shown->id);
    $this->getJson("/api/v1/products/{$hidden->slug}")->assertNotFound();
    $this->getJson("/api/v1/products/{$hidden->slug}/reviews")->assertNotFound();
});

it('refuses to add a suspended store\'s variant to the cart', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->for(productOfSuspendedStore())->create(['stock' => 5]);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant_id');
});

it('takes a store\'s catalog offline when the admin suspends it, and back when re-activated', function () {
    $product = Product::factory()->create();
    $store = $product->store;

    $store->update(['status' => 'suspended']);
    $this->get(route('products.show', $product))->assertNotFound();

    $store->update(['status' => 'active']);
    $this->get(route('products.show', $product))->assertOk();
});
