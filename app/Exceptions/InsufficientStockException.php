<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly ProductVariant $variant, public readonly int $requested)
    {
        parent::__construct(
            "Insufficient stock for variant #{$variant->id} ({$variant->sku}): requested {$requested}, have {$variant->stock}."
        );
    }
}
