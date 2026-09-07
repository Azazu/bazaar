<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\Payment;
use App\States\Order\Cancelled;
use App\States\Order\Refunded;
use App\States\SubOrder\Processing as SubOrderProcessing;
use Livewire\Livewire;

/*
 * Orders are financial records. Gate::before lets an admin through every policy, so the
 * Filament resource itself must refuse create/edit/delete and offer only the domain
 * operations (cancel/refund through OrderService).
 */

it('does not let admins create, edit or delete orders in the panel', function () {
    $order = Order::factory()->create();

    expect(OrderResource::canCreate())->toBeFalse()
        ->and(OrderResource::canEdit($order))->toBeFalse()
        ->and(OrderResource::canDelete($order))->toBeFalse()
        ->and(OrderResource::canDeleteAny())->toBeFalse();

    $this->actingAs(admin());
    $this->get('/admin/orders/create')->assertNotFound();      // no such route any more
    $this->get("/admin/orders/{$order->id}/edit")->assertNotFound();
    $this->get("/admin/orders/{$order->id}")->assertOk();      // read-only view is the only detail page
});

it('shows an order read-only with its items, sub-orders and payment', function () {
    [$order, $variant, $subOrder] = paidOrderWithStock();

    $this->actingAs(admin())
        ->get("/admin/orders/{$order->id}")
        ->assertOk()
        ->assertSee($order->buyer->name)
        ->assertSee($variant->product->title)
        ->assertSee($subOrder->store->name)
        ->assertSee(money($order->total_cents, $order->currency))
        ->assertDontSee('Save changes');
});

it('offers only view, cancel and refund on the orders table', function () {
    $order = Order::factory()->create();
    $this->actingAs(admin());

    Livewire::test(ListOrders::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('cancel')
        ->assertTableActionExists('refund')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableActionVisible('cancel', $order)
        ->assertTableActionHidden('refund', $order); // nothing paid yet
});

it('cancels through OrderService from the table: refund, restock, voided payout', function () {
    [$order, $variant, $subOrder] = paidOrderWithStock();
    $this->actingAs(admin());

    Livewire::test(ListOrders::class)
        ->callTableAction('cancel', $order)
        ->assertHasNoTableActionErrors();

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($variant->fresh()->stock)->toBe(5)
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled')
        ->and(Payment::where('order_id', $order->id)->value('status'))->toBe('refunded');
});

it('refunds through OrderService from the order page', function () {
    [$order, $variant, $subOrder] = paidOrderWithStock();
    $this->actingAs(admin());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('refund')
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBeInstanceOf(Refunded::class)
        ->and($variant->fresh()->stock)->toBe(5)
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled');
});

it('hides cancel once a vendor has started fulfilment', function () {
    [$order, , $subOrder] = paidOrderWithStock();
    $subOrder->fresh()->status->transitionTo(SubOrderProcessing::class);
    $this->actingAs(admin());

    Livewire::test(ListOrders::class)->assertTableActionHidden('cancel', $order);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('cancel');
});
