<?php

namespace App\States\Order;

class Paid extends OrderState
{
    public static string $name = 'paid';

    public function label(): string
    {
        return 'Paid';
    }
}
