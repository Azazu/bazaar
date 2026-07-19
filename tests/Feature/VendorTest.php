<?php

use App\Models\Store;
use App\Models\SubOrder;
use App\Models\User;
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
