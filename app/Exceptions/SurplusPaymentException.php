<?php

namespace App\Exceptions;

use App\Models\Payment;
use RuntimeException;

/**
 * A payment succeeded at the provider for an order that is no longer pending — another
 * attempt already settled it (or it was cancelled meanwhile). The money must go back.
 * Internal to PaymentService: thrown inside the confirm transaction to roll it back.
 */
class SurplusPaymentException extends RuntimeException
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct("Payment #{$payment->id} ({$payment->transaction_id}) is surplus for order #{$payment->order_id}.");
    }
}
