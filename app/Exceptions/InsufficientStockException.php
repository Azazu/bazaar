<?php

namespace App\Exceptions;

use App\Models\ProductVariant;

class InsufficientStockException extends UnfulfillableOrderException
{
    public function __construct(public readonly ProductVariant $variant, public readonly int $requested)
    {
        parent::__construct(
            "Insufficient stock for variant #{$variant->id} ({$variant->sku}): requested {$requested}, have {$variant->stock}."
        );
    }

    public function itemLabel(): string
    {
        return trim(($this->variant->product->title ?? '').' — '.$this->variant->name, ' —');
    }
}
