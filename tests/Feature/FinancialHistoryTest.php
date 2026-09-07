<?php

use App\Exceptions\DeletionBlockedException;
use App\Filament\Resources\Stores\Pages\EditStore;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Payout;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Account\AccountService;
use App\States\SubOrder\Delivered;
use App\States\SubOrder\Processing;
use App\States\SubOrder\Shipped;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Livewire\Volt\Volt;

/*
 * HI-001: orders, payments and payouts are financial history. Deleting an account or a
 * store must never take them along — accounts are anonymised, stores archived, and the
 * schema refuses to cascade even if someone bypasses the models.
 */

/** A buyer with one fully delivered, paid order from $store (or a fresh store). */
function buyerWithHistory(?Store $store = null): array
{
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $store ??= $variant->product->store;
    $variant->product->update(['store_id' => $store->id]);

    $order = orderForVariant($variant, 1);
    $subOrder = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $store->id, 'subtotal_cents' => $order->subtotal_cents]);
    pay($order);

    $subOrder->refresh()->status->transitionTo(Processing::class);
    $subOrder->status->transitionTo(Shipped::class);
    $subOrder->status->transitionTo(Delivered::class);

    return [$order->buyer, $order->refresh(), $subOrder->refresh()];
}

it('anonymises a buyer who deletes their account and keeps every financial record', function () {
    [$buyer, $order, $subOrder] = buyerWithHistory();
    $buyer->createToken('phone');
    $this->actingAs($buyer);

    Volt::test('profile.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

    // History: untouched, still pointing at the (anonymised) account.
    expect(Order::find($order->id))->not->toBeNull()
        ->and(Order::find($order->id)->buyer_id)->toBe($buyer->id)
        ->and(OrderItem::where('order_id', $order->id)->count())->toBe(1)
        ->and(Payment::where('order_id', $order->id)->count())->toBe(1)
        ->and(PaymentEvent::count())->toBe(1)
        ->and(Payout::where('sub_order_id', $subOrder->id)->count())->toBe(1);

    // The person: gone. Soft-deleted, scrubbed, no tokens, can't sign in.
    $ghost = User::withTrashed()->find($buyer->id);
    expect(User::find($buyer->id))->toBeNull()
        ->and($ghost->trashed())->toBeTrue()
        ->and($ghost->name)->toBe('Deleted user')
        ->and($ghost->email)->toBe("deleted-{$buyer->id}@users.invalid")
        ->and($ghost->tokens()->count())->toBe(0)
        ->and(Auth::attempt(['email' => $buyer->email, 'password' => 'password']))->toBeFalse()
        ->and(Order::find($order->id)->buyer)->toBeNull(); // notifications to this order's buyer become no-ops
});

it('refuses to delete an account while it has an order in progress', function () {
    $buyer = User::factory()->create();
    Order::factory()->paid()->create(['buyer_id' => $buyer->id]);
    $this->actingAs($buyer);

    Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('account')
        ->assertNoRedirect();

    expect(User::find($buyer->id))->not->toBeNull()->and($buyer->fresh()->name)->not->toBe('Deleted user');
    $this->assertAuthenticatedAs($buyer);
});

it('archives a store from the admin panel and keeps its sub-orders and payouts', function () {
    $store = Store::factory()->create();
    [, $order, $subOrder] = buyerWithHistory($store);
    $product = $store->products()->first();
    $this->actingAs(admin());

    Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])->callAction('delete')->assertHasNoActionErrors();

    expect(Store::find($store->id))->toBeNull()                      // archived: out of every ordinary query…
        ->and(Store::withTrashed()->find($store->id)->trashed())->toBeTrue()
        ->and(SubOrder::find($subOrder->id))->not->toBeNull()          // …history intact…
        ->and(SubOrder::find($subOrder->id)->store->id)->toBe($store->id) // …and still readable through the relation
        ->and(Payout::where('store_id', $store->id)->count())->toBe(1)
        ->and(Product::find($product->id))->not->toBeNull()
        ->and($product->fresh()->isVisible())->toBeFalse();            // but nothing of it is for sale any more

    $this->get(route('products.show', $product))->assertNotFound();
});

it('refuses to archive a store with orders in progress', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->paid()->create();
    SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $store->id, 'status' => 'paid']);

    expect(fn () => app(AccountService::class)->archiveStore($store))->toThrow(DeletionBlockedException::class);
    expect(fn () => $store->delete())->toThrow(DeletionBlockedException::class); // the model guards the bare path too
    expect(Store::find($store->id))->not->toBeNull();

    // The admin gets told, not a 500.
    $this->actingAs(admin());
    Livewire::test(EditStore::class, ['record' => $store->getRouteKey()])->callAction('delete')->assertNotified();
    expect(Store::find($store->id))->not->toBeNull();
});

it('refuses to close a vendor account while the store has orders in progress, leaving both untouched', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->paid()->create();
    SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $store->id, 'status' => 'paid']);

    expect(fn () => app(AccountService::class)->close($store->owner))->toThrow(DeletionBlockedException::class);

    // Atomic: the store is still active and the owner is neither anonymised nor deleted.
    expect(Store::find($store->id))->not->toBeNull()
        ->and(User::find($store->owner_id)?->name)->toBe($store->owner->name)
        ->and(User::find($store->owner_id)?->email)->toBe($store->owner->email);
});

it('archives the vendor\'s store together with the vendor\'s account', function () {
    $store = Store::factory()->create();
    [, , $subOrder] = buyerWithHistory($store); // delivered: nothing open
    $this->actingAs(admin());

    Livewire::test(EditUser::class, ['record' => $store->owner->getRouteKey()])->callAction('delete')->assertHasNoActionErrors();

    expect(User::find($store->owner_id))->toBeNull()
        ->and(Store::find($store->id))->toBeNull()
        ->and(Store::withTrashed()->find($store->id))->not->toBeNull()
        ->and(SubOrder::find($subOrder->id))->not->toBeNull();
});

it('makes orphan products and cascading deletes impossible at the schema level', function () {
    $product = Product::factory()->create();
    $store = $product->store;
    [, $order] = buyerWithHistory($store);

    expect(fn () => Product::factory()->create(['store_id' => null]))->toThrow(QueryException::class);
    expect(fn () => $store->forceDelete())->toThrow(QueryException::class);                 // products + sub-orders point here
    expect(fn () => $order->buyer->forceDelete())->toThrow(QueryException::class);          // orders point here
    expect(fn () => $order->delete())->toThrow(QueryException::class);                      // items, payments, sub-orders point here

    expect(Store::find($store->id))->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull()
        ->and(Product::find($product->id))->not->toBeNull();
});
