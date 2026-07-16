<?php

namespace App\Enums;

enum StoreStatus: string
{
    case Pending = 'pending';     // awaiting admin moderation
    case Active = 'active';       // approved — products can be sold
    case Suspended = 'suspended'; // blocked by admin
}
