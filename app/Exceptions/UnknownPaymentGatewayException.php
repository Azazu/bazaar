<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment names a gateway this build doesn't know (or the configuration for it is gone).
 * Nothing may be finalised locally for that payment: a refund we can't send is not a refund.
 */
class UnknownPaymentGatewayException extends RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("Unknown payment gateway \"{$name}\". Known gateways: fake, stripe.");
    }
}
