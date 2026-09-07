<?php

use App\Models\Order;
use App\Models\Store;
use App\Models\SubOrder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** A user with the given role. */
function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/*
| The authorization matrix, in one place: who may reach which area, and who may touch
| whose data. These are the assertions a reviewer looks for first.
*/

it('sends guests to the login page instead of buyer-only pages', function () {
    $order = Order::factory()->create();

    $this->get(route('checkout.index'))->assertRedirect(route('login'));
    $this->get(route('orders.show', $order))->assertRedirect(route('login'));
    $this->get(route('vendor.orders'))->assertRedirect(route('login'));
});

it('answers 401 rather than a redirect on the API', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
    $this->getJson('/api/v1/cart')->assertUnauthorized();
    $this->postJson('/api/v1/checkout')->assertUnauthorized();
});

it('lets only admins into the admin panel', function () {
    $this->get('/admin')->assertRedirect(); // guest → Filament login

    $this->actingAs(userWithRole('customer'))->get('/admin')->assertForbidden();
    $this->actingAs(userWithRole('vendor'))->get('/admin')->assertForbidden();
    $this->actingAs(userWithRole('admin'))->get('/admin')->assertSuccessful();
});

it('keeps non-vendors out of the vendor area', function () {
    $this->actingAs(userWithRole('customer'))->get(route('vendor.orders'))->assertForbidden();
    $this->actingAs(userWithRole('vendor'))->get(route('vendor.orders'))->assertSuccessful();
});

it('shows a buyer only their own orders, on the web and through the API', function () {
    $buyer = User::factory()->create();
    $own = Order::factory()->create(['buyer_id' => $buyer->id]);
    $someoneElses = Order::factory()->create();

    $this->actingAs($buyer)->get(route('orders.show', $own))->assertOk();
    $this->actingAs($buyer)->get(route('orders.show', $someoneElses))->assertForbidden();

    Sanctum::actingAs($buyer);
    $this->getJson("/api/v1/orders/{$own->id}")->assertOk();
    $this->getJson("/api/v1/orders/{$someoneElses->id}")->assertForbidden();
    $this->postJson("/api/v1/orders/{$someoneElses->id}/pay")->assertForbidden();
});

it('lets a vendor act only on sub-orders of their own store', function () {
    $vendor = userWithRole('vendor');
    $ownStore = Store::factory()->create(['owner_id' => $vendor->id]);
    $own = SubOrder::factory()->create(['store_id' => $ownStore->id, 'status' => 'paid']);
    $foreign = SubOrder::factory()->create(['status' => 'paid']);

    expect($vendor->can('update', $own))->toBeTrue()
        ->and($vendor->can('update', $foreign))->toBeFalse()
        ->and($vendor->can('view', $foreign))->toBeFalse();
});

it('lets an admin bypass policies but not domain invariants', function () {
    $admin = userWithRole('admin');
    $someoneElses = Order::factory()->create();

    // Gate::before grants admins every policy...
    expect($admin->can('view', $someoneElses))->toBeTrue();

    // ...but a paid order still cannot be paid again (checked in PaymentService, not the policy).
    Sanctum::actingAs($admin);
    $paid = Order::factory()->paid()->create();
    $this->postJson("/api/v1/orders/{$paid->id}/pay")->assertConflict();
});
