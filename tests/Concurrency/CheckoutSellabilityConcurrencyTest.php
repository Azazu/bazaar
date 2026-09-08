<?php

use App\Enums\ProductStatus;
use App\Exceptions\CheckoutBlockedException;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\CartStorage;
use App\Services\Cart\CartStorageFactory;
use App\Services\Checkout\CheckoutService;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => requiresDatabaseConcurrency());

/*
 * HI-003: a vendor unpublishing a product races a buyer checking it out. Checkout locks the
 * product row and reads its status from that locked row, so whichever commits first decides:
 * an unpublish that lands first makes the checkout refuse; a checkout that lands first is a
 * legitimate order for a product that was published at the time.
 */

it('refuses the checkout whenever the unpublish committed first', function () {
    foreach (range(1, 3) as $round) {
        $variant = ProductVariant::factory()->create(['stock' => 10]);
        $buyer = User::factory()->create();

        $result = raceInParallel(2, function (int $i) use ($variant, $buyer) {
            if ($i === 1) {
                // Vendor side: unpublish under the product's own row lock and note whether an order
                // already existed at that moment. "ok" here means: nobody had ordered it yet.
                return DB::transaction(function () use ($variant) {
                    Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail()
                        ->update(['status' => ProductStatus::Draft]);

                    return OrderItem::where('product_variant_id', $variant->id)->doesntExist();
                });
            }

            try {
                app()->bind(CartStorage::class, fn () => app(CartStorageFactory::class)->forGuest());
                app(CartService::class)->add($variant->id, 1);
                app(CheckoutService::class)->place(
                    $buyer,
                    ['name' => 'Racer', 'line1' => '1 Lock St', 'city' => 'Town', 'postcode' => '00000', 'country' => 'US'],
                    'standard',
                );

                return true;
            } catch (CheckoutBlockedException) {
                return false;
            }
        });

        // Both "ok" would mean the unpublish saw no order *and* the checkout still went through
        // — exactly the window this closes. Any other split is a consistent ordering.
        expect($result['failed'])->toBe(0, "round {$round}")
            ->and($result['ok'])->toBe(1, "round {$round}: exactly one side may win");

        // The unpublish always lands eventually; when an order exists too, it was placed first (the unpublish saw it).
        expect($variant->product->fresh()->status)->toBe(ProductStatus::Draft, "round {$round}");
    }
});
