<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A line of an order cannot be delivered: it sold out, or the variant behind it no longer
 * exists. Raised inside the payment transaction, which rolls back; PaymentService then
 * refunds the charge and cancels the order instead of marking it paid.
 */
abstract class UnfulfillableOrderException extends RuntimeException
{
    /** Human-readable name of the line for the buyer's notification ("Product — Variant"). */
    abstract public function itemLabel(): string;
}
