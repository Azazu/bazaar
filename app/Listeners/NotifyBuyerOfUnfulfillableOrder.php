<?php

namespace App\Listeners;

use App\Events\OrderUnfulfillable;
use App\Notifications\OrderUnfulfillableNotice;

class NotifyBuyerOfUnfulfillableOrder
{
    public function handle(OrderUnfulfillable $event): void
    {
        $event->order->buyer?->notify(new OrderUnfulfillableNotice($event->order, $event->item));
    }
}
