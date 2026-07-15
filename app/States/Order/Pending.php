<?php

namespace App\States\Order;

class Pending extends OrderState
{
    public static string $name = 'pending';

    public function label(): string
    {
        return 'Pending';
    }
}
