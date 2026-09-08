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
use App\Services\Payment\PaymentIntentData;
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

        public function refund(Payment $payment): ?string
        {
            $this->log->append($payment->transaction_id);

            return null;
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

/*
 * One order, one open attempt (CR-001). Two Pay clicks, a reload or a retry after a lost
 * response must never leave two intents that can both be charged.
 */

/** A FakePaymentGateway that logs refunds into $log and can be told the open intent is dead. */
function loggingGateway(ArrayObject $log, bool $resumable = true): FakePaymentGateway
{
    return new class($log, $resumable) extends FakePaymentGateway
    {
        public function __construct(private ArrayObject $log, private bool $resumable) {}

        public function resumeIntent(Payment $payment): ?PaymentIntentData
        {
            return $this->resumable ? parent::resumeIntent($payment) : null;
        }

        public function refund(Payment $payment): ?string
        {
            $this->log->append($payment->transaction_id);

            return null;
        }
    };
}

it('resumes the open attempt instead of minting a second chargeable intent', function () {
    $order = Order::factory()->create();

    $first = app(PaymentService::class)->start($order);
    $second = app(PaymentService::class)->start($order); // double click / reload / lost response

    expect($second->payment->id)->toBe($first->payment->id)
        ->and($second->payment->transaction_id)->toBe($first->payment->transaction_id)
        ->and(Payment::where('order_id', $order->id)->count())->toBe(1);

    // The resumed attempt is still fully payable.
    app(PaymentService::class)->confirm('evt_resumed', $second->payment->transaction_id);
    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($order->fresh()->payment_id)->toBe($first->payment->id);
});

it('starts a fresh attempt only when the provider can no longer complete the open one', function () {
    app()->bind(PaymentGateway::class, fn () => loggingGateway(new ArrayObject, resumable: false));
    $order = Order::factory()->create();

    $first = app(PaymentService::class)->start($order)->payment;
    $second = app(PaymentService::class)->start($order)->payment;

    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->status)->toBe('failed')      // closed, can't be confirmed into a double charge
        ->and($second->status)->toBe('pending')
        ->and(Payment::where('order_id', $order->id)->where('status', 'pending')->count())->toBe(1);
});

it('starts a fresh attempt after the provider reported the previous one failed', function () {
    $order = Order::factory()->create();

    $first = app(PaymentService::class)->start($order)->payment;
    app(PaymentService::class)->fail($first->transaction_id); // e.g. card declined

    $second = app(PaymentService::class)->start($order)->payment;

    expect($second->id)->not->toBe($first->id)
        ->and(Payment::where('order_id', $order->id)->where('status', 'pending')->count())->toBe(1);
});

it('refunds a second successful charge instead of keeping two payments for one order', function () {
    $refunds = new ArrayObject;
    app()->bind(PaymentGateway::class, fn () => loggingGateway($refunds));

    $variant = ProductVariant::factory()->create(['stock' => 5]);
    $order = orderForVariant($variant, 1);

    // Two intents for one order — what the old start() produced on a double click, and what a
    // provider-side retry can still produce. Both get confirmed.
    $winner = app(PaymentService::class)->start($order)->payment;
    $stray = $order->payments()->create([
        'gateway' => 'fake', 'transaction_id' => 'fake_stray', 'status' => 'pending',
        'amount_cents' => $order->total_cents, 'currency' => $order->currency,
    ]);

    app(PaymentService::class)->confirm('evt_winner', $winner->transaction_id);
    app(PaymentService::class)->confirm('evt_stray', $stray->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Paid::class)
        ->and($order->fresh()->payment_id)->toBe($winner->id)          // the order remembers who paid it
        ->and($winner->fresh()->status)->toBe('succeeded')
        ->and($stray->fresh()->status)->toBe('refunded')
        ->and($refunds->getArrayCopy())->toBe(['fake_stray'])           // money went back, once
        ->and($variant->fresh()->stock)->toBe(4)                        // stock moved once (OrderPaid ran once)
        ->and(PaymentEvent::where('event_id', 'evt_stray')->exists())->toBeTrue();

    // Redelivery of the stray's event, and a brand-new event id for the same stray charge:
    // both are no-ops, no second refund.
    app(PaymentService::class)->confirm('evt_stray', $stray->transaction_id);
    app(PaymentService::class)->confirm('evt_stray_again', $stray->transaction_id);
    expect($refunds)->toHaveCount(1)
        ->and($order->fresh()->status)->toBeInstanceOf(Paid::class);
});

it('refunds a charge that lands after the order was cancelled', function () {
    $refunds = new ArrayObject;
    app()->bind(PaymentGateway::class, fn () => loggingGateway($refunds));

    $order = Order::factory()->create();
    $payment = app(PaymentService::class)->start($order)->payment;
    $order->status->transitionTo(Cancelled::class); // buyer cancelled while the charge was in flight

    app(PaymentService::class)->confirm('evt_late', $payment->transaction_id);

    expect($order->fresh()->status)->toBeInstanceOf(Cancelled::class)
        ->and($order->fresh()->payment_id)->toBeNull()
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and($refunds->getArrayCopy())->toBe([$payment->transaction_id]);
});
