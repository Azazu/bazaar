<?php

namespace App\States\Order;

class Processing extends OrderState
{
    public static string $name = 'processing';

    public function label(): string
    {
        return 'Processing';
    }
}
