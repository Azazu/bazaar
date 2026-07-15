<?php

namespace App\States\Order;

class Refunded extends OrderState
{
    public static string $name = 'refunded';

    public function label(): string
    {
        return 'Refunded';
    }
}
