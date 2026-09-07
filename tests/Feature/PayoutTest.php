<?php

use App\Events\OrderPaid;
use App\Filament\Pages\ManageMarketplace;
use App\Models\Order;
use App\Models\SubOrder;
use App\Models\User;
use App\Settings\MarketplaceSettings;
use Livewire\Livewire;

it('creates a payout per sub-order with the platform commission deducted', function () {
    MarketplaceSettings::fake(['commission_rate' => '0.10']);

    $order = Order::factory()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id, 'subtotal_cents' => 10000]);

    OrderPaid::dispatch($order);

    $payout = $sub->fresh()->payout;

    expect($payout)->not->toBeNull()
        ->and($payout->commission_cents)->toBe(1000) // 10% of 10000
        ->and($payout->amount_cents)->toBe(9000)     // vendor keeps the rest
        ->and($payout->store_id)->toBe($sub->store_id);
});

it('splits money with no lost cents when the rate does not divide evenly', function () {
    MarketplaceSettings::fake(['commission_rate' => '0.10']);

    $order = Order::factory()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id, 'subtotal_cents' => 9999]);

    OrderPaid::dispatch($order);
    $payout = $sub->fresh()->payout;

    // commission + vendor amount must reconcile exactly to the sub-order subtotal
    expect($payout->commission_cents + $payout->amount_cents)->toBe(9999);
});

it('uses the commission rate configured in the admin panel', function () {
    MarketplaceSettings::fake(['commission_rate' => '0.15']);

    $order = Order::factory()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id, 'subtotal_cents' => 10000]);

    OrderPaid::dispatch($order);

    expect($sub->fresh()->payout->commission_cents)->toBe(1500);
});

it('lets an admin change the commission as a percentage, stored as an exact decimal', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(ManageMarketplace::class)
        ->assertFormSet(['commission_rate' => '10.00'])
        ->fillForm(['commission_rate' => 12.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(MarketplaceSettings::class)->refresh()->commission_rate)->toBe('0.1250');
});
