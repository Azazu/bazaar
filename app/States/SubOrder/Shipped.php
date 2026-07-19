<?php

namespace App\States\SubOrder;

class Shipped extends SubOrderState
{
    public static string $name = 'shipped';

    public function label(): string
    {
        return 'Shipped';
    }
}
