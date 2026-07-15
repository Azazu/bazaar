<?php

namespace App\States\Order;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class OrderState extends State
{
    /** Human-readable label for the state (for UI). */
    abstract public function label(): string;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            // Happy path
            ->allowTransition(Pending::class, Paid::class)
            ->allowTransition(Paid::class, Processing::class)
            ->allowTransition(Processing::class, Shipped::class)
            ->allowTransition(Shipped::class, Delivered::class)
            // Cancellation (only before it ships)
            ->allowTransition(Pending::class, Cancelled::class)
            ->allowTransition(Paid::class, Cancelled::class)
            // Refunds (once money has changed hands)
            ->allowTransition(Paid::class, Refunded::class)
            ->allowTransition(Processing::class, Refunded::class)
            ->allowTransition(Shipped::class, Refunded::class)
            ->allowTransition(Delivered::class, Refunded::class);
    }
}
