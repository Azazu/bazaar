<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An account or a store cannot be removed right now: it still takes part in an order that
 * is not finished. Thrown from the model's deleting hook so every path (profile page,
 * admin panel, console) hits the same rule; callers turn it into a message for the user.
 */
class DeletionBlockedException extends RuntimeException {}
