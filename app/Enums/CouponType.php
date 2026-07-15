<?php

namespace App\Enums;

enum CouponType: string
{
    case Percent = 'percent'; // value = 1..100 (percent off)
    case Fixed = 'fixed';     // value = amount off in minor units (cents)
}
