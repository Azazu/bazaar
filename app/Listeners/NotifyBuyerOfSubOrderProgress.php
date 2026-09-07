<?php

namespace App\Listeners;

use App\Models\SubOrder;
use App\Notifications\SubOrderStatusUpdated;
use App\States\SubOrder\Delivered;
use App\States\SubOrder\Processing;
use App\States\SubOrder\Shipped;
use App\States\SubOrder\SubOrderState;
use Spatie\ModelStates\Events\StateChanged;

/**
 * Hooks the state machine itself, so the buyer hears about progress no matter where the
 * transition came from (vendor dashboard, admin, a future API). Only fulfilment steps
 * are worth a mail — pending → paid is already covered by the order confirmation.
 */
class NotifyBuyerOfSubOrderProgress
{
    private const NOTIFY_ON = [Processing::class, Shipped::class, Delivered::class];

    public function handle(StateChanged $event): void
    {
        $subOrder = $event->model;

        $state = $event->finalState;

        if (! $subOrder instanceof SubOrder || ! $state instanceof SubOrderState || ! in_array($state::class, self::NOTIFY_ON, true)) {
            return;
        }

        $subOrder->order?->buyer?->notify(new SubOrderStatusUpdated($subOrder, $state));
    }
}
