<?php

namespace App\Listeners;

use App\Models\SubOrder;
use Spatie\ModelStates\Events\StateChanged;

/** Every sub-order transition re-derives the parent's state (see Order::syncStateFromSubOrders). */
class SyncOrderWithSubOrders
{
    public function handle(StateChanged $event): void
    {
        if ($event->model instanceof SubOrder) {
            $event->model->order?->syncStateFromSubOrders();
        }
    }
}
