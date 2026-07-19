<?php

namespace App\States\SubOrder;

class Paid extends SubOrderState
{
    public static string $name = 'paid';

    public function label(): string
    {
        return 'Paid';
    }
}
