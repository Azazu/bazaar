<?php

use App\Events\OrderPaid;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderNotPayableException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Notifications\OrderUnfulfillableNotice;
use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentService;
use App\States\Order\Cancelled;
use App\States\Order\Paid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

it('marks the order paid on a successful payment', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create(); // pending
    $payment = app(PaymentService::class)->start($order)->payment;

    app(PaymentService::class)->confirm('evt_1', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($payment->fresh()->status)->toBe('succeeded');

    Event::assertDispatched(OrderPaid::class, 1);
});

it('is idempotent when the same payment event arrives twice', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;

    // A provider may deliver the same webhook more than once.
    app(PaymentService::class)->confirm('evt_dup', $payment->transaction_id);
    app(PaymentService::class)->confirm('evt_dup', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and(Payment::where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1);

    Event::assertDispatched(OrderPaid::class, 1); // fired exactly once
});

it('applies the effect once when the provider retries with a new event id', function () {
    Event::fake([OrderPaid::class]);

    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;

    // Not every retry reuses the event id — a provider may raise a fresh event for the same
    // charge. The payment's own status is the second guard behind the event ledger.
    app(PaymentService::class)->confirm('evt_first', $payment->transaction_id);
    app(PaymentService::class)->confirm('evt_second', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and(Payment::where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1);

    Event::assertDispatched(OrderPaid::class, 1);
});

it('ignores a webhook for an unknown transaction', function () {
    expect(fn () => app(PaymentService::class)->confirm('evt_unknown', 'no_such_transaction'))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses to start a payment for an order that is not pending', function () {
    $order = Order::factory()->paid()->create();

    expect(fn () => app(PaymentService::class)->start($order))
        ->toThrow(OrderNotPayableException::class);

    expect(Payment::where('order_id', $order->id)->count())->toBe(0);
});

it('refunds and cancels when an item sold out between checkout and the payment webhook', function () {
    Notification::fake();
    $refunds = new ArrayObject; // shared by handle, so the gateway below can report into it
    app()->bind(PaymentGateway::class, fn () => new class($refunds) extends FakePaymentGateway
    {
        public function __construct(private ArrayObject $log) {}

        public function refund(Payment $payment): void
        {
            $this->log->append($payment->transaction_id);
        }
    });

    $variant = ProductVariant::factory()->create(['stock' => 1]);
    $order = orderForVariant($variant, 1);
    $subOrder = SubOrder::factory()->create(['order_id' => $order->id, 'store_id' => $variant->product->store_id]);
    $payment = app(PaymentService::class)->start($order)->payment;

    $variant->update(['stock' => 0]); // someone else took the last unit while the customer was paying

    app(PaymentService::class)->confirm('evt_soldout', $payment->transaction_id); // no exception: handled

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($subOrder->fresh()->status)->toBeInstanceOf(App\States\SubOrder\Cancelled::class)
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($refunds->getArrayCopy())->toBe([$payment->transaction_id])
        ->and($variant->fresh()->stock)->toBe(0)             // never went negative, nothing "restored"
        ->and($subOrder->fresh()->payout)->toBeNull()        // no payout for an order we never fulfilled
        ->and(PaymentEvent::where('event_id', 'evt_soldout')->exists())->toBeTrue();

    Notification::assertSentTo($order->buyer, OrderUnfulfillableNotice::class,
        fn ($n) => str_contains($n->soldOutItem, $variant->name));

    // A redelivery of the same event is a no-op — no second refund.
    app(PaymentService::class)->confirm('evt_soldout', $payment->transaction_id);
    expect($refunds)->toHaveCount(1);
});

it('refuses to start a payment for an order whose items are already out of stock', function () {
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    $order = orderForVariant($variant, 1);

    expect(fn () => app(PaymentService::class)->start($order))->toThrow(InsufficientStockException::class);
    expect(Payment::count())->toBe(0); // no intent, no charge
});
