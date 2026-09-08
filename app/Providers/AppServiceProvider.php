<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Cart\CartStorage;
use App\Services\Cart\CartStorageFactory;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayRegistry;
use App\Services\Payment\StripeTestMode;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Payment provider for *new* payments, chosen by config: the sandbox needs no keys, Stripe
        // needs a secret. Existing payments are always handled by the gateway they were made with
        // (PaymentGatewayRegistry::for), whatever this is set to now.
        $this->app->bind(PaymentGateway::class, fn (Application $app): PaymentGateway => $app
            ->make(PaymentGatewayRegistry::class)
            ->named((string) config('bazaar.payment_gateway')));

        // GD is what the php image ships with (with WebP); swap for Imagick here if it ever matters.
        $this->app->singleton(ImageManager::class, fn (): ImageManager => new ImageManager(GdDriver::class));

        // Test-mode keys only, verified every time the client is built (see also boot()).
        $this->app->singleton(StripeClient::class, function (): StripeClient {
            StripeTestMode::assertConfigured();

            return new StripeClient((string) config('services.stripe.secret'));
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
        // Fail fast: in Stripe mode the app refuses to start with anything but test-mode keys.
        if (config('bazaar.payment_gateway') === 'stripe') {
            StripeTestMode::assertConfigured();
        }

        // Admins bypass all policy checks.
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);

        $this->configureRateLimiting();

        // API reference: every protected route is documented as bearer-token (Sanctum) secured.
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
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
