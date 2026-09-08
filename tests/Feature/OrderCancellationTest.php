<?php

use App\Jobs\RefundPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Order\OrderService;
use App\Services\Order\SubOrderService;
use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Pending;
use App\States\Order\Processing;
use App\States\Order\Refunded;
use App\States\Order\Shipped;
use App\States\SubOrder\Cancelled as SubOrderCancelled;
use App\States\SubOrder\Processing as SubOrderProcessing;
use App\States\SubOrder\Refunded as SubOrderRefunded;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

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
    $subOrder->status->transitionTo(SubOrderProcessing::class);
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

it('shows the shortage on the order page instead of failing', function () {
    $buyer = User::factory()->create();
    $variant = ProductVariant::factory()->create(['stock' => 0, 'name' => 'XL / red']);
    $order = orderForVariant($variant, 1);
    $order->update(['buyer_id' => $buyer->id]);

    $this->actingAs($buyer);

    Volt::test('pages.orders.show', ['order' => $order->fresh()])
        ->call('pay')
        ->assertHasNoErrors()
        ->assertSee('XL / red')
        ->assertSee('no longer available');

    expect($order->fresh()->status)->toBeInstanceOf(Pending::class);
});

/*
 * CR-003: a reversal happens exactly once, and the provider refund is a durable second step.
 */

it('refuses a second cancellation of the same order and restores stock only once', function () {
    [$order, $variant] = paidOrderWithStock();
    $stale = Order::find($order->id); // another tab / a retried request, holding the old "paid" snapshot

    app(OrderService::class)->cancel($order);
    expect(fn () => app(OrderService::class)->cancel($stale))->toThrow(CouldNotPerformTransition::class);

    expect($variant->fresh()->stock)->toBe(5) // 3 + 2, not 3 + 2 + 2
        ->and(Payment::where('order_id', $order->id)->count())->toBe(1);
});

it('refuses a second refund of the same order and restores stock only once', function () {
    [$order, $variant] = paidOrderWithStock();
    $stale = Order::find($order->id);

    app(OrderService::class)->refund($order);
    expect(fn () => app(OrderService::class)->refund($stale))->toThrow(CouldNotPerformTransition::class);
    expect(fn () => app(OrderService::class)->cancel($stale))->toThrow(CouldNotPerformTransition::class);

    expect($variant->fresh()->stock)->toBe(5);
});

it('reverses the order first and returns the money in a retried job, so a provider outage cannot lose either', function () {
    $attempts = new ArrayObject;
    app()->bind(PaymentGateway::class, fn () => new class($attempts) extends FakePaymentGateway
    {
        public function __construct(private ArrayObject $attempts) {}

        public function refund(Payment $payment): void
        {
            $this->attempts->append($payment->transaction_id);

            if (count($this->attempts) === 1) {
                throw new RuntimeException('provider timeout');
            }
        }
    });
    Queue::fake([RefundPayment::class]);
    [$order, $variant, $subOrder] = paidOrderWithStock();

    app(OrderService::class)->cancel($order);

    $payment = Payment::where('order_id', $order->id)->firstOrFail();
    Queue::assertPushed(RefundPayment::class, fn (RefundPayment $job) => $job->payment->is($payment));
    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)  // local half is committed…
        ->and($variant->fresh()->stock)->toBe(5)
        ->and($subOrder->fresh()->payout->status)->toBe('cancelled')
        ->and($payment->status)->toBe('refund_pending');                // …the money is recorded as owed back

    // First run: the provider is down. The job fails (the queue will retry it), nothing is marked refunded.
    expect(fn () => (new RefundPayment($payment))->handle(app(PaymentService::class)))->toThrow(RuntimeException::class);
    expect($payment->fresh()->status)->toBe('refund_pending');

    // Retry: succeeds; a further run is a no-op (the payment is no longer refund_pending).
    (new RefundPayment($payment))->handle(app(PaymentService::class));
    (new RefundPayment($payment))->handle(app(PaymentService::class));
    expect($payment->fresh()->status)->toBe('refunded')
        ->and($attempts)->toHaveCount(2);
});

it('re-queues refunds still pending after the grace period', function () {
    Queue::fake([RefundPayment::class]);
    $order = Order::factory()->paid()->create();
    $stuck = $order->payments()->create(['gateway' => 'fake', 'transaction_id' => 'fake_stuck', 'status' => 'refund_pending', 'amount_cents' => 100, 'currency' => 'USD']);
    $fresh = $order->payments()->create(['gateway' => 'fake', 'transaction_id' => 'fake_fresh', 'status' => 'refund_pending', 'amount_cents' => 100, 'currency' => 'USD']);
    $done = $order->payments()->create(['gateway' => 'fake', 'transaction_id' => 'fake_done', 'status' => 'refunded', 'amount_cents' => 100, 'currency' => 'USD']);
    Payment::whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);

    $this->artisan('payments:retry-refunds')->assertSuccessful();

    Queue::assertPushed(RefundPayment::class, 1);
    Queue::assertPushed(RefundPayment::class, fn (RefundPayment $job) => $job->payment->is($stuck));
});

it('gives a vendor and a cancelling buyer one consistent outcome', function () {
    // Cancellation landed first: the vendor's stale "paid" sub-order can't be advanced.
    [$order, , $subOrder] = paidOrderWithStock();
    $stale = SubOrder::find($subOrder->id);
    app(OrderService::class)->cancel($order);

    expect(fn () => app(SubOrderService::class)->advance($stale, SubOrderProcessing::class))
        ->toThrow(CouldNotPerformTransition::class);
    expect($subOrder->fresh()->status)->toBeInstanceOf(SubOrderCancelled::class);

    // Advance landed first: the buyer's cancellation is refused (it's a refund from here on).
    [$order, $variant, $subOrder] = paidOrderWithStock();
    app(SubOrderService::class)->advance($subOrder->fresh(), SubOrderProcessing::class);

    expect(fn () => app(OrderService::class)->cancel($order))->toThrow(CouldNotPerformTransition::class);
    expect($order->fresh()->status)->toBeInstanceOf(Processing::class)
        ->and($variant->fresh()->stock)->toBe(3);
});
