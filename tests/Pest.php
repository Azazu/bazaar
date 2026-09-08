<?php

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
| Concurrency tests fork real processes, so the data they race over must be committed and
| visible outside the parent — RefreshDatabase's transaction would hide it. DatabaseMigrations
| migrates fresh per test instead and commits. These tests need MySQL (row locks) and skip
| elsewhere; see the `test-concurrency` make target.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Concurrency');

// Schema-upgrade tests roll migrations back and forward, so they need a real (fresh) schema
// per test rather than RefreshDatabase's transaction.
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Migrations');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** A user with the admin role (Filament panel access, Gate::before bypass). */
function admin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

/**
 * A paid order for 2 units of a 5-unit variant, with its sub-order and (via OrderPaid) payout.
 *
 * @return array{0: Order, 1: ProductVariant, 2: SubOrder}
 */
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

/** Build a pending order with a single line for the given variant. */
function orderForVariant(ProductVariant $variant, int $qty): Order
{
    $order = Order::factory()->create([
        'subtotal_cents' => $variant->price_cents * $qty,
        'shipping_cents' => 0,
        'total_cents' => $variant->price_cents * $qty,
    ]);

    $order->items()->create([
        'product_variant_id' => $variant->id,
        'sku' => $variant->sku,
        'product_title' => $variant->product?->title,
        'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents,
        'qty' => $qty,
    ]);

    return $order->load('items');
}

/** Pay an order through the (sandbox) payment service. */
function pay(Order $order): void
{
    $payment = app(PaymentService::class)->start($order)->payment;
    app(PaymentService::class)->confirm('evt_'.uniqid(), $payment->transaction_id);
}

/** Give a user a paid order containing the variant (so they qualify to review it). */
function paidPurchase(User $user, ProductVariant $variant): void
{
    $order = Order::factory()->create(['buyer_id' => $user->id, 'status' => 'paid']);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_title' => $variant->product->title,
        'variant_name' => $variant->name,
        'unit_price_cents' => $variant->price_cents,
        'qty' => 1,
    ]);
}

/**
 * Run $workers copies of $worker in forked processes, all starting at once, and report how
 * they ended: ['ok' => n, 'rejected' => n, 'failed' => n].
 *
 * The worker returns true when its operation succeeded and false when the domain correctly
 * refused it; anything thrown counts as 'failed'. Results travel back as exit codes, since a
 * forked child shares nothing with the parent but the database.
 *
 * @param  Closure(int): bool  $worker
 * @return array{ok: int, rejected: int, failed: int}
 */
function raceInParallel(int $workers, Closure $worker): array
{
    $pids = [];

    foreach (range(1, $workers) as $i) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork a worker process.');
        }

        if ($pid === 0) {
            // Child: a fresh connection is mandatory — the inherited socket is shared with the parent.
            DB::purge();
            DB::reconnect();

            try {
                $code = $worker($i) ? 0 : 1;
            } catch (Throwable) {
                $code = 2;
            }

            exit($code);
        }

        $pids[] = $pid;
    }

    $tally = ['ok' => 0, 'rejected' => 0, 'failed' => 0];

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);

        $key = match (pcntl_wexitstatus($status)) {
            0 => 'ok',
            1 => 'rejected',
            default => 'failed',
        };

        $tally[$key]++;
    }

    return $tally;
}

/** Skip a test unless it can actually observe concurrency: real MySQL row locks plus pcntl. */
function requiresDatabaseConcurrency(): void
{
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('Needs MySQL row locks; the default suite runs on SQLite. Use `make test-concurrency`.');
    }

    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('Needs the pcntl extension to fork racing processes.');
    }
}
