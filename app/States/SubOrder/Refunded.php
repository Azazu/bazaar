<?php

namespace App\States\SubOrder;

class Refunded extends SubOrderState
{
    public static string $name = 'refunded';

    public function label(): string
    {
        return 'Refunded';
    }
}
