<?php

use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderNotPayableException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Opt in to `throttle:api` on the api group (limits defined in AppServiceProvider).
        $middleware->throttleApi();

        // spatie/laravel-permission route middleware aliases (e.g. 'role:vendor')
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Stock ran out between checkout and payment: the payment transaction rolled back
        // and the order is still pending — tell the API client why instead of a 500.
        $exceptions->render(fn (InsufficientStockException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 422)
            : null);

        // Paying an order that is no longer pending is a state conflict, not a server error.
        $exceptions->render(fn (OrderNotPayableException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 409)
            : null);

        // Same for any transition the state machine rejects (e.g. cancelling a shipped order).
        $exceptions->render(fn (CouldNotPerformTransition $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $e->getMessage()], 409)
            : null);
    })->create();
