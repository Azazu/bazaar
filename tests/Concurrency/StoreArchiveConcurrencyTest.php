<?php

use App\Exceptions\CheckoutBlockedException;
use App\Exceptions\DeletionBlockedException;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Account\AccountService;
use App\Services\Cart\CartService;
use App\Services\Cart\CartStorage;
use App\Services\Cart\CartStorageFactory;
use App\Services\Checkout\CheckoutService;

beforeEach(fn () => requiresDatabaseConcurrency());

/*
 * HI-001: archiving a store races a checkout that contains its product. Both lock the store
 * row (checkout also the buyer's row) in the same order and re-check on the locked snapshot,
 * so exactly one wins: either the order lands and the archive is refused because a sub-order
 * is now open, or the store is archived and the checkout is refused. What must never happen
 * is a pending sub-order pointing at an archived store.
 */

it('never leaves a pending sub-order under an archived store when checkout races the archive', function () {
    foreach (range(1, 3) as $round) {
        $variant = ProductVariant::factory()->create(['stock' => 10]);
        $store = $variant->product->store;
        $buyer = User::factory()->create();

        $result = raceInParallel(2, function (int $i) use ($store, $variant, $buyer) {
            try {
                if ($i === 1) {
                    app(AccountService::class)->archiveStore(Store::findOrFail($store->id));
                } else {
                    // Each worker is its own process: a session-backed cart is enough to feed the checkout.
                    app()->bind(CartStorage::class, fn () => app(CartStorageFactory::class)->forGuest());
                    app(CartService::class)->add($variant->id, 1);
                    app(CheckoutService::class)->place(
                        $buyer,
                        ['name' => 'Racer', 'line1' => '1 Lock St', 'city' => 'Town', 'postcode' => '00000', 'country' => 'US'],
                        'standard',
                    );
                }

                return true;
            } catch (DeletionBlockedException|CheckoutBlockedException) {
                return false; // the other side got there first — a legitimate refusal
            }
        });

        $archived = Store::withTrashed()->findOrFail($store->id)->trashed();
        $openSubOrders = SubOrder::where('store_id', $store->id)->count();

        expect($result)->toBe(['ok' => 1, 'rejected' => 1, 'failed' => 0], "round {$round}: exactly one side must win")
            ->and($archived ? $openSubOrders : 1)->toBe($archived ? 0 : 1, "round {$round}: archived store with sub-orders, or order without store");
    }
});
