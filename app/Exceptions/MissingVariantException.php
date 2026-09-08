<?php

namespace App\Exceptions;

use App\Models\OrderItem;

/** The variant an order line was placed for has been removed from the catalog since. */
class MissingVariantException extends UnfulfillableOrderException
{
    public function __construct(public readonly OrderItem $item)
    {
        parent::__construct("Order item #{$item->id} ({$item->sku}) refers to a variant that no longer exists.");
    }

    public function itemLabel(): string
    {
        return trim($this->item->product_title.' — '.$this->item->variant_name, ' —');
    }
}
