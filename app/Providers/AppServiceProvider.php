<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Payment\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Payment provider. Sandbox stand-in for now — swap for a real StripeGateway
        // (backlog) without touching PaymentService or the checkout flow.
        $this->app->bind(PaymentGateway::class, FakePaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admins bypass all policy checks.
        Gate::before(fn (User $user) => $user->hasRole('admin') ? true : null);
    }
}
