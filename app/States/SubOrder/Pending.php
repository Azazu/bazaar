<?php

namespace App\States\SubOrder;

class Pending extends SubOrderState
{
    public static string $name = 'pending';

    public function label(): string
    {
        return 'Pending';
    }
}
