<?php

use App\Models\User;
use Livewire\Volt\Volt;

it('shows the user menu on every storefront page and lets the user log out', function () {
    $user = User::factory()->create(['name' => 'Dana']);
    $this->actingAs($user);

    $this->get(route('catalog.index'))->assertSeeVolt('user-menu')->assertSee('Dana');
    $this->get(route('profile'))->assertSeeVolt('user-menu')->assertSee('Profile');

    Volt::test('user-menu')->call('logout')->assertRedirect('/');
    $this->assertGuest();
});

it('offers the vendor dashboard link only to vendors', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');
    $vendor = User::factory()->create();
    $vendor->assignRole('vendor');

    $this->actingAs($customer)->get(route('catalog.index'))->assertDontSee('Vendor dashboard');
    $this->actingAs($vendor)->get(route('catalog.index'))->assertSee('Vendor dashboard');
});
