<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Order\OrderService;
use App\States\Order\Cancelled;
use App\States\Order\Processing;
use App\States\Order\Refunded;
use App\States\Order\Shipped;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use App\States\SubOrder\Refunded as SubOrderRefunded;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/** A paid order for 2 units of a 5-unit variant, with its sub-order and (via OrderPaid) payout. */
function paidOrderWithStock(): array
{
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 2);
    $subOrder = SubOrder::factory()->create([
        'order_id' => $order->id,
        'store_id' => $variant->product->store_id,
        'subtotal_cents' => $order->subtotal_cents,
    ]);

    pay($order); // decrements stock to 3, marks the sub-order paid, creates a payout

    return [$order->refresh(), $variant, $subOrder];
}

it('cancels a pending order without touching stock', function () {
    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 2);
    $subOrder = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $variant->product->store_id]);

    app(OrderService::class)->cancel($order);

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($subOrder->fresh()->status)->toBeInstanceOf(SubOrderCancelled::class)
        ->and($variant->fresh()->stock)->toBe(5)
        ->and(Payment::count())->toBe(0);
});

it('cancels a paid order: refunds, restores stock and voids the payout', function () {
    [$order, $variant, $subOrder] = paidOrderWithStock();
    expect($variant->fresh()->stock)->toBe(3)->and($subOrder->fresh()->payout->status)->toBe('pending');

    app(OrderService::class)->cancel($order);

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($subOrder->fresh()->status)->toBeInstanceOf(SubOrderCancelled::class)
        ->and($variant->fresh()->stock)->toBe(5)
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled')
        ->and(Payment::where('order_id', $order->id)->value('status'))->toBe('refunded');
});

it('refunds a paid order: restores stock and voids the payout', function () {
    [$order, $variant, $subOrder] = paidOrderWithStock();

    app(OrderService::class)->refund($order);

    expect($order->fresh()->status)->toBeInstanceOf(Refunded::class)
        ->and($subOrder->fresh()->status)->toBeInstanceOf(SubOrderRefunded::class)
        ->and($variant->fresh()->stock)->toBe(5)
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled')
        ->and(Payment::where('order_id', $order->id)->value('status'))->toBe('refunded');
});

it('refuses an illegal transition before any side effect', function () {
    [$order, $variant] = paidOrderWithStock();
    $order->status->transitionTo(Processing::class);
    $order->status->transitionTo(Shipped::class);

    expect(fn () => app(OrderService::class)->cancel($order->fresh()))
        ->toThrow(CouldNotPerformTransition::class);

    expect($variant->fresh()->stock)->toBe(3) // nothing restored
        ->and(Payment::where('order_id', $order->id)->value('status'))->toBe('succeeded'); // nothing refunded
});

it('refuses to cancel once any vendor has started fulfilment — that is a refund', function () {
    [$order, $variant, $subOrder] = paidOrderWithStock();
    $subOrder->refresh(); // pay() moved it to paid behind this instance's back
    $subOrder->status->transitionTo(App\States\SubOrder\Processing::class);
    $subOrder->status->transitionTo(App\States\SubOrder\Shipped::class);
    $order->refresh();

    expect($order->isCancellable())->toBeFalse()
        ->and($order->buyer->can('cancel', $order))->toBeFalse();
    expect(fn () => app(OrderService::class)->cancel($order))->toThrow(CouldNotPerformTransition::class);
    expect($variant->fresh()->stock)->toBe(3); // nothing restored for shipped goods

    // ...but a refund is still the right tool and does everything a cancellation would.
    app(OrderService::class)->refund($order->fresh());

    expect($order->fresh()->status)->toBeInstanceOf(Refunded::class)
        ->and($subOrder->fresh()->status)->toBeInstanceOf(SubOrderRefunded::class)
        ->and($variant->fresh()->stock)->toBe(5);
});

it('lets a buyer cancel their own order while it is pending or paid, and no later', function () {
    $buyer = User::factory()->create();
    $pending = Order::factory()->create(['buyer_id' => $buyer->id]);
    $paid = Order::factory()->paid()->create(['buyer_id' => $buyer->id]);
    $shipped = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'shipped']);
    $someoneElses = Order::factory()->create();

    expect($buyer->can('cancel', $pending))->toBeTrue()
        ->and($buyer->can('cancel', $paid))->toBeTrue()
        ->and($buyer->can('cancel', $shipped))->toBeFalse()
        ->and($buyer->can('cancel', $someoneElses))->toBeFalse()
        ->and($buyer->can('refund', $paid))->toBeFalse(); // refunds are for admins
});

it('cancels an order through the API', function () {
    Sanctum::actingAs($buyer = User::factory()->create());
    $order = Order::factory()->create(['buyer_id' => $buyer->id]);

    $this->postJson("/api/v1/orders/{$order->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('answers 409 when the API cancellation is not allowed by the state machine', function () {
    // An admin passes the policy (Gate::before) but not the state machine.
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
    $shipped = Order::factory()->create(['status' => 'shipped']);

    $this->postJson("/api/v1/orders/{$shipped->id}/cancel")->assertConflict();
});

it('forbids cancelling another buyer\'s order through the API', function () {
    Sanctum::actingAs(User::factory()->create());
    $order = Order::factory()->create();

    $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertForbidden();

    expect($order->fresh()->status)->not->toBeInstanceOf(Cancelled::class);
});

it('lets a buyer cancel from the order page', function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->create(['buyer_id' => $buyer->id]);

    $this->actingAs($buyer);

    Volt::test('pages.orders.show', ['order' => $order])
        ->assertSee('Cancel order')
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertDontSee('Cancel order');

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class);
});
