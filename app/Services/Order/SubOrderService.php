<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\SubOrder;
use App\States\SubOrder\SubOrderState;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

/**
 * A vendor moving their sub-order forward (paid → processing → shipped → delivered).
 *
 * The move is decided on a locked, fresh copy: the parent order row is locked first, then
 * the sub-order — the same order OrderService uses when it cancels or refunds — so a vendor
 * clicking "start processing" while the buyer cancels gets one of two clean outcomes: the
 * cancellation landed first and the advance is refused, or the advance landed first and the
 * cancellation is refused (the order is no longer cancellable). Never a processing sub-order
 * under a cancelled order.
 */
class SubOrderService
{
    /** @param  class-string<SubOrderState>  $target */
    public function advance(SubOrder $subOrder, string $target): SubOrder
    {
        DB::transaction(function () use ($subOrder, $target) {
            // Parent first (same order as OrderService::reverse), then the sub-order itself.
            Order::query()->whereKey($subOrder->order_id)->lockForUpdate()->first();

            $locked = SubOrder::query()->whereKey($subOrder->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($target)) {
                throw CouldNotPerformTransition::notFound($locked->status->getValue(), $target::getMorphClass(), $locked);
            }

            $locked->status->transitionTo($target);
        });

        return $subOrder->refresh();
    }
}
