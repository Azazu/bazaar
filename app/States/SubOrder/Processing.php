<?php

namespace App\States\SubOrder;

class Processing extends SubOrderState
{
    public static string $name = 'processing';

    public function label(): string
    {
        return 'Processing';
    }
}
