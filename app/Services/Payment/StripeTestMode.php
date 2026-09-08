<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * Bazaar is a demo: it only ever talks to Stripe in test mode. This is enforced by code, not
 * by convention — a live key in .env would otherwise turn a walkthrough into real charges.
 * Checked when the app boots in Stripe mode and again whenever the Stripe client is built.
 * Error messages name the key's *prefix* only; the key itself never reaches a log.
 */
final class StripeTestMode
{
    /** Prefixes Stripe uses for test-mode keys (standard and restricted secret keys, publishable key). */
    private const array SECRET_PREFIXES = ['sk_test_', 'rk_test_'];

    private const string PUBLISHABLE_PREFIX = 'pk_test_';

    /** Validate the keys from config/services.php. */
    public static function assertConfigured(): void
    {
        self::assert(config('services.stripe.secret'), config('services.stripe.key'));
    }

    public static function assert(?string $secret, ?string $publishable): void
    {
        if ($secret === null || $secret === '') {
            throw new RuntimeException('PAYMENT_GATEWAY=stripe requires STRIPE_SECRET (a test-mode key).');
        }

        if (! self::hasPrefix($secret, self::SECRET_PREFIXES)) {
            throw new RuntimeException(sprintf(
                'Bazaar runs Stripe in test mode only: STRIPE_SECRET must start with sk_test_ (got a "%s" key). Refusing to start.',
                self::prefix($secret),
            ));
        }

        if ($publishable !== null && $publishable !== '' && ! self::hasPrefix($publishable, [self::PUBLISHABLE_PREFIX])) {
            throw new RuntimeException(sprintf(
                'Bazaar runs Stripe in test mode only: STRIPE_KEY must start with pk_test_ (got a "%s" key). Refusing to start.',
                self::prefix($publishable),
            ));
        }
    }

    /** True for an event Stripe sent from live mode — one this application must never act on. */
    public static function isLiveEvent(object $event): bool
    {
        return (bool) ($event->livemode ?? false);
    }

    /** @param  list<string>  $prefixes */
    private static function hasPrefix(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The "sk_live_"-style prefix of a key: enough to explain the problem, nothing secret. */
    private static function prefix(string $key): string
    {
        return preg_match('/^[a-z]{2}_(?:live|test)_/', $key, $m) === 1 ? $m[0] : substr($key, 0, 3).'…';
    }
}
