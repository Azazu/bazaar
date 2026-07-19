<?php

namespace App\States\SubOrder;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class SubOrderState extends State
{
    abstract public function label(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            // Happy path (vendor drives it once the parent order is paid)
            ->allowTransition(Pending::class, Paid::class)
            ->allowTransition(Paid::class, Processing::class)
            ->allowTransition(Processing::class, Shipped::class)
            ->allowTransition(Shipped::class, Delivered::class)
            // Cancellation before shipping
            ->allowTransition(Pending::class, Cancelled::class)
            ->allowTransition(Paid::class, Cancelled::class)
            // Refunds after money changed hands
            ->allowTransition(Paid::class, Refunded::class)
            ->allowTransition(Processing::class, Refunded::class)
            ->allowTransition(Shipped::class, Refunded::class)
            ->allowTransition(Delivered::class, Refunded::class);
    }
}
