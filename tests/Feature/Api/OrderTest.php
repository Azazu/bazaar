<?php

use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists only the buyer\'s own orders', function () {
    Sanctum::actingAs($buyer = User::factory()->create());
    $mine = Order::factory()->count(2)->create(['buyer_id' => $buyer->id]);
    Order::factory()->create(); // someone else's

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.id', fn (array $ids) => collect($ids)->sort()->values()->all() === $mine->pluck('id')->sort()->values()->all());
});

it('shows the buyer\'s own order', function () {
    Sanctum::actingAs($buyer = User::factory()->create());
    $order = Order::factory()->create(['buyer_id' => $buyer->id]);

    $this->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.status', 'pending');
});

it('forbids viewing another buyer\'s order', function () {
    Sanctum::actingAs(User::factory()->create());
    $order = Order::factory()->create();

    $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
});
