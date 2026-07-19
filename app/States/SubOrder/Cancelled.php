<?php

namespace App\States\SubOrder;

class Cancelled extends SubOrderState
{
    public static string $name = 'cancelled';

    public function label(): string
    {
        return 'Cancelled';
    }
}
