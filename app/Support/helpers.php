<?php

use Brick\Money\Money;

if (! function_exists('money')) {
    /**
     * Format an integer amount of minor units (cents) as a localized money string,
     * e.g. money(39902) => "$399.02". Money math must go through brick/money, never floats.
     */
    function money(int $cents, string $currency = 'USD'): string
    {
        return Money::ofMinor($cents, $currency)->formatToLocale('en_US');
    }
}
