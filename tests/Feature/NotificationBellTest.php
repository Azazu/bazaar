<?php

use App\Models\Order;
use App\Notifications\OrderConfirmed;
use Livewire\Volt\Volt;

it('shows unread in-app notifications and marks them read', function () {
    $order = Order::factory()->paid()->create();
    $order->buyer->notifyNow(new OrderConfirmed($order));
    $this->actingAs($order->buyer);

    $this->get(route('catalog.index'))->assertSeeVolt('notification-bell');

    Volt::test('notification-bell')
        ->assertSee("Order #{$order->id} is paid")
        ->assertSee('Mark all as read')
        ->call('markAllRead')
        ->assertSee("You're all caught up.");

    expect($order->buyer->unreadNotifications()->count())->toBe(0);
});
