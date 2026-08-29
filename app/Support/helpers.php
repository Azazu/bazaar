<?php

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
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

if (! function_exists('cents')) {
    /**
     * Parse a human-entered amount ("12.50") into minor units (1250); null if blank or not a number.
     * Inverse of money(). Goes through brick/money so the conversion is exact — never (int) ($x * 100).
     */
    function cents(string|int|null $amount, string $currency = 'USD'): ?int
    {
        $amount = trim((string) $amount);

        if ($amount === '') {
            return null;
        }

        try {
            return Money::of($amount, $currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();
        } catch (MathException) {
            return null;
        }
    }
}
