<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Cart\CartStorage;
use App\Services\Cart\CartStorageFactory;
use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\StripeGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Payment provider, chosen by config: the sandbox needs no keys, Stripe needs a secret.
        // PaymentService and the checkout flow don't know which one they got.
        $this->app->bind(PaymentGateway::class, fn (Application $app): PaymentGateway => match (config('bazaar.payment_gateway')) {
            'stripe' => $app->make(StripeGateway::class),
            'fake' => new FakePaymentGateway,
            default => throw new RuntimeException('Unknown PAYMENT_GATEWAY: '.config('bazaar.payment_gateway')),
        });

        $this->app->singleton(StripeClient::class, function (): StripeClient {
            $secret = config('services.stripe.secret');

            if (blank($secret)) {
                throw new RuntimeException('PAYMENT_GATEWAY=stripe requires STRIPE_SECRET (a test-mode key).');
            }

            return new StripeClient($secret);
        });

        // Cart storage follows the viewer: account cart once authenticated (web session or
        // API token), session cart for guests. Resolved lazily so the auth middleware has run.
        $this->app->bind(CartStorage::class, fn (Application $app): CartStorage => $app
            ->make(CartStorageFactory::class)
            ->forViewer($app->make(AuthFactory::class)->guard()->user()));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admins bypass all policy checks.
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);

        $this->configureRateLimiting();
    }

    /**
     * API rate limits: a general per-client budget, and a much tighter one for issuing
     * tokens so credentials can't be brute-forced through the API.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
