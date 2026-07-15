<?php

namespace App\States\Order;

class Shipped extends OrderState
{
    public static string $name = 'shipped';

    public function label(): string
    {
        return 'Shipped';
    }
}
