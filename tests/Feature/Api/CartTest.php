<?php

use App\Models\ProductVariant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/v1/cart')->assertUnauthorized();
});

it('adds a variant and returns the full cart', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['price_cents' => 1000, 'stock' => 5]);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'qty' => 2])
        ->assertCreated()
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.total_cents', 2000)
        ->assertJsonPath('data.items.0.variant.id', $variant->id)
        ->assertJsonPath('data.items.0.variant.product.id', $variant->product_id);
});

it('rejects a variant of an unpublished product', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $variant->product->update(['status' => 'draft']);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant_id');
});

it('rejects an out-of-stock variant', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['stock' => 0]);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant_id');
});

it('updates and removes a line', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['price_cents' => 500, 'stock' => 9]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id]);

    $this->patchJson("/api/v1/cart/items/{$variant->id}", ['qty' => 4])
        ->assertOk()
        ->assertJsonPath('data.count', 4)
        ->assertJsonPath('data.total_cents', 2000);

    $this->deleteJson("/api/v1/cart/items/{$variant->id}")
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});

it('clears the cart', function () {
    Sanctum::actingAs(User::factory()->create());
    $variant = ProductVariant::factory()->create(['stock' => 9]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id]);

    $this->deleteJson('/api/v1/cart')->assertNoContent();
    $this->getJson('/api/v1/cart')->assertJsonPath('data.count', 0);
});

it('keeps each user\'s cart separate', function () {
    $variant = ProductVariant::factory()->create(['stock' => 9]);

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id])->assertCreated();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/cart')->assertJsonPath('data.count', 0);
});

it('does not let PATCH add a line the cart never had', function () {
    Sanctum::actingAs(User::factory()->create());
    $draft = ProductVariant::factory()->create();
    $draft->product->update(['status' => 'draft']); // POST would refuse this one

    $this->patchJson("/api/v1/cart/items/{$draft->id}", ['qty' => 3])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('variant');

    $this->getJson('/api/v1/cart')->assertJsonCount(0, 'data.items');
});
