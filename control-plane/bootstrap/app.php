<?php

use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\UseSessionRealm;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // The first middleware of each realm's route group (routes/api.php).
            'realm' => UseSessionRealm::class,
            // After auth:<guard>: the session must have completed the second factor.
            'two-factor' => RequireSecondFactor::class,
        ]);

        // The gate answers before a route parameter is resolved, so half a
        // login learns nothing about what exists.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RequireSecondFactor::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
