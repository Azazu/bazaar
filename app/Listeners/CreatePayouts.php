<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\Payout;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

class CreatePayouts
{
    /**
     * On payment, record a payout per sub-order: the vendor's share is the sub-order
     * subtotal minus the platform commission. Money math goes through brick/money so
     * the rounding of the commission is exact — no lost cents when the rate doesn't
     * divide evenly. Idempotent via one-payout-per-sub-order.
     */
    public function handle(OrderPaid $event): void
    {
        // String, not float — brick/math needs an exact decimal (a float multiplier is lossy).
        $rate = (string) config('bazaar.commission_rate');
        $currency = $event->order->currency;

        foreach ($event->order->subOrders as $subOrder) {
            $subtotal = Money::ofMinor($subOrder->subtotal_cents, $currency);
            $commission = $subtotal->multipliedBy($rate, RoundingMode::HalfUp);
            $vendorAmount = $subtotal->minus($commission);

            Payout::firstOrCreate(
                ['sub_order_id' => $subOrder->id],
                [
                    'store_id' => $subOrder->store_id,
                    'commission_cents' => $commission->getMinorAmount()->toInt(),
                    'amount_cents' => $vendorAmount->getMinorAmount()->toInt(),
                    'status' => 'pending',
                ],
            );
        }
    }
}
