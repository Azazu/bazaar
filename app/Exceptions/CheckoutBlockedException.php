<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The cart can't be turned into an order any more: between browsing and checkout the buyer's
 * account was closed, or a store in the cart was archived or suspended. Detected on locked
 * rows inside the checkout transaction, so nothing is created.
 */
class CheckoutBlockedException extends RuntimeException {}
