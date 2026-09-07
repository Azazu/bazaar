<?php

namespace App\Listeners;

use App\Events\OrderUnfulfillable;
use App\Notifications\OrderUnfulfillableNotice;

class NotifyBuyerOfUnfulfillableOrder
{
    public function handle(OrderUnfulfillable $event): void
    {
        $item = trim(($event->soldOut->product->title ?? 'An item').' — '.$event->soldOut->name);

        $event->order->buyer?->notify(new OrderUnfulfillableNotice($event->order, $item));
    }
}
