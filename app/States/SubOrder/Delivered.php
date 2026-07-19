<?php

namespace App\States\SubOrder;

class Delivered extends SubOrderState
{
    public static string $name = 'delivered';

    public function label(): string
    {
        return 'Delivered';
    }
}
