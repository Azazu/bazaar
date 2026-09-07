<?php

use App\Models\Order;
use App\Models\Store;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Order\OrderService;
use App\States\SubOrder\Cancelled;
use App\States\SubOrder\Paid;
use App\States\SubOrder\Processing;
use Livewire\Volt\Volt;

/** @return array{0: User, 1: Store} */
function vendorWithStore(): array
{
    $vendor = User::factory()->create();
    $vendor->assignRole('vendor');
    $store = Store::factory()->create(['owner_id' => $vendor->id]);

    return [$vendor, $store];
}

it('lets a vendor advance their own sub-order', function () {
    [$vendor, $store] = vendorWithStore();
    $sub = SubOrder::factory()->create(['store_id' => $store->id, 'status' => 'paid']);

    $this->actingAs($vendor);

    Volt::test('pages.vendor.orders.index')
        ->call('advance', $sub->id)
        ->assertHasNoErrors();

    expect($sub->fresh()->status)->toBeInstanceOf(Processing::class);
});

it('forbids a vendor from touching another store\'s sub-order', function () {
    [$vendorA] = vendorWithStore();
    [, $storeB] = vendorWithStore();
    $subB = SubOrder::factory()->create(['store_id' => $storeB->id, 'status' => 'paid']);

    $this->actingAs($vendorA);

    Volt::test('pages.vendor.orders.index')
        ->call('advance', $subB->id)
        ->assertForbidden();

    expect($subB->fresh()->status)->toBeInstanceOf(Paid::class); // unchanged
});

it('blocks non-vendors from the vendor area', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->get(route('vendor.orders'))
        ->assertForbidden();
});

it('tells the vendor when the order was cancelled under them instead of advancing it', function () {
    $owner = User::factory()->create();
    $owner->assignRole('vendor');
    $store = Store::factory()->create(['owner_id' => $owner->id]);
    $order = Order::factory()->paid()->create();
    $sub = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $store->id, 'status' => 'paid']);

    app(OrderService::class)->cancel($order);

    $this->actingAs($owner);
    Volt::test('pages.vendor.orders.index')
        ->call('advance', $sub->id)
        ->assertHasNoErrors()
        ->assertSee('can no longer be advanced');

    expect($sub->fresh()->status)->toBeInstanceOf(Cancelled::class);
});
